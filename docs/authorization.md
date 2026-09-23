# Authorization engineer guide

This guide explains the approved clean command/query authorization model. Its
fresh-template implementation, verification and fresh review are complete; user acceptance
is pending.
Historical task documents explain how the design evolved, but their intermediate schema
compatibility rules are not current operating instructions.

## The short version

Authorization follows one rule:

> Infrastructure says who is acting. A module voter decides whether that actor may run
> a command or query.

Every command and query handler has one `#[Authorize]` attribute. At container compile
time, `CqrsPass` checks that attribute and builds the route from the message to its voter.
At runtime, `AuthorizationMiddleware` asks a private Symfony decision manager to vote on
the actual message object. The handler runs only when the vote allows it.

Permissions are global, coarse capabilities such as `task_tracking.task.view`. They are
only one input to a decision. The owning module's voter still applies actor, account,
ownership, and message-specific rules. An account with every permission does not
automatically gain access to every business object.

## Terms

| Term | Meaning |
| --- | --- |
| Authentication | Establishes who the caller is. Web login and JWT authentication are examples. |
| Actor | The trusted identity used for one command/query execution. |
| Authorization | Decides whether that actor may execute one command or query. |
| Permission | A global, assignable capability such as `task_tracking.task.view`. |
| Role | A named, persisted bundle of permissions. |
| Assignment | A role assignment or direct permission grant for a subject UUID. |
| Voter | Same-module code that makes the final admission decision. |
| Subject | An opaque UUID that can receive roles and direct permission grants. |
| Public action | An internal bus action that allows every actor, including anonymous. It is not automatically an HTTP endpoint. |

## End-to-end flow

For an ordinary account command, the flow is:

```text
HTTP session or JWT
        |
        v
ExecutionContext creates Account actor
        |
        v
CommandBus dispatches command
        |
        v
input validation
        |
        v
Doctrine command transaction starts
        |
        v
AuthorizationMiddleware
        |
        v
private AccessDecisionManager
        |
        v
same-module voter
   |              |
   |              +--> optional entitlement/account/ownership reads
   v
grant, deny, or operational failure
        |
        v
handler -> result validation -> final flush and commit
```

A query bus dispatch does not start a Doctrine command transaction. A nested query may
still run inside the transaction already owned by an enclosing command. Input validation
still happens before authorization, and result validation still happens after the handler.

A false vote becomes `AuthorizationDenied`. A dependency failure, such as unavailable
authorization storage, remains an operational exception instead of being presented as a
normal deny. Either outcome prevents the command handler from committing.

The important boundaries are:

- Controllers, console commands, and event listeners dispatch messages; they do not make
  the business authorization decision.
- Handler code does not decide whether it was allowed to run.
- Actor identity comes from trusted infrastructure, never from an account ID supplied in
  the command or query.
- A voter may read state needed for its decision, but it cannot dispatch commands or
  events while deciding.

## Actors

`Actor` is a small immutable value owned by Platform authorization code. It has an
`ActorKind`, an optional account UUID, and an optional operator scope.

| Kind | Where it comes from | Typical use |
| --- | --- | --- |
| `Anonymous` | No fully authenticated main HTTP request, or a console/worker process with no trusted override | Denied by restricted actions; accepted by a public action |
| `Account` | A fully authenticated web session or JWT with a UUID user identifier | Ordinary account commands and queries |
| `Operator` | An approved console adapter using `OperatorExecution` | Trusted operational commands with one exact scope |
| `Authentication` | `AccountUserProvider` using `AuthenticationExecution` | The narrowly scoped password-hash upgrade command |

The available operator scopes are `accounts`, `assignments`, `catalogue`, and `tasks`.
The scope is exact: an `assignments` operator is not a `tasks` operator.

`ExecutionContext` pins the actor when the first authorization frame starts. Nested bus
calls reuse that actor until the outer frame finishes. Code cannot change authority in
the middle of an invocation.

### HTTP

For HTTP, `ExecutionContext` requires a main request and a token Symfony considers fully
authenticated. The token's user identifier must be a UUID. Symfony role names are not
used for business authorization.

The web firewall and bus authorization are separate controls. A firewall may require an
authenticated request before a controller runs, while the command/query voter applies
the business rule. Conversely, marking a bus action public does not create a route or
weaken an existing firewall rule.

### Console and workers

Console and worker processes are anonymous by default. An approved console adapter can
temporarily establish one operator scope around a dispatch. This is an infrastructure
capability, not a string that arbitrary application code may put in a message.

Queued events do not store the publishing actor. An asynchronous worker therefore starts
with an anonymous actor. Event listeners must not rely on the publisher's account or
operator authority.

## The three handler declaration forms

Every command/query handler class must use exactly one of these forms.

### Permission plus context

Use this when the action belongs in the assignable capability catalogue:

```php
#[Authorize(
    voter: TaskTrackingVoter::class,
    permission: TaskPermission::View,
    label: 'View tasks',
)]
final readonly class GetTaskHandler
{
    // ...
}
```

The permission is coarse. `TaskTrackingVoter` still checks the actor, live account,
Task existence, and ownership. `GetTask` and `ListTasks` share the same permission and
must use the same stable label.

### Context only

Use this when the action needs an actor-specific rule but should not be assignable as a
permission:

```php
#[Authorize(voter: AuthenticatingVoter::class)]
final readonly class UpgradePasswordHashHandler
{
    // ...
}
```

The voter admits only an `Authentication` actor whose account UUID matches the command.
Other examples are self-only identity lookup and voter-only account-existence support
reads.

### Public at the bus boundary

Use this only when every actor, including anonymous, may execute the action:

```php
#[Authorize(public: true)]
final readonly class ExampleHandler
{
    // ...
}
```

The compiler routes this action to `PublicAccessVoter`. A public action:

- is still required to go through the command/query bus;
- still goes through validation, transactions, and result validation;
- is allowed only when it is in the compiler-generated public-message inventory;
- does not add a permission or capability;
- does not create an HTTP route;
- does not bypass firewall rules.

There are currently no public production handlers. The public mechanism is present and
verified, but remains untagged in the production container while its inventory is empty.

Do not combine `public: true` with a voter, permission, or label. Do not reference
`PublicAccessVoter` from a handler.

## What the compiler checks

`CqrsPass` turns declarations into runtime configuration during container compilation.
It fails the build when the authorization graph is incomplete or inconsistent.

It checks that:

- every command/query handler has exactly one class-level `#[Authorize]`;
- event listeners do not use `#[Authorize]`;
- a restricted voter is concrete, final, in the same module, and in the approved voter
  namespace;
- a permission is a string-backed case from that module's Domain `*Permission` or
  `*PermissionEnum` enum;
- a permission key uses the module prefix;
- all operations sharing a permission use one stable label;
- public metadata is not mixed with restricted metadata;
- voters have the exact private, lazy, custom-tag service wiring;
- command, query, and event middleware remain in their required order;
- the private decision manager contains only business bus voters.

It then builds:

- a command/query class to voter-class route map;
- each module voter's command/query to permission map;
- the installed permission catalogue;
- capability descriptions with key, label, read/write classification, and operations;
- the exact public-message inventory.

There is no authorization YAML and no runtime scan for permissions. Code declarations
are the source of the installed catalogue.

## Runtime admission

The command bus middleware order is:

1. invocation scope;
2. message policy;
3. bus-name stamp;
4. input validation;
5. command transaction;
6. authorization;
7. result validation;
8. handler.

The query bus has the same order without command transaction middleware.

`AuthorizationMiddleware` performs these steps:

1. Enter the execution context and obtain a credential-free `AuthorizationToken`.
2. Find the voter class compiled for the message.
3. Mark the invocation as being inside an authorization decision.
4. Ask the private decision manager to decide using the voter class as the attribute and
   the command/query object as the subject.
5. Throw `AuthorizationDenied` when the route is missing or the decision denies.
6. Verify that no caught nested failure poisoned the invocation.
7. Leave the decision frame and continue to the handler.
8. Release the actor frame in `finally`.

The token contains only the immutable actor and internal support-read provenance. It has
no credentials, Symfony roles, principal object, or caller-controlled attributes. It is
never installed in Symfony's global token storage.

`AuthorizationDenied` exposes only a fixed `Access denied.` message and whether the actor
was non-anonymous. HTTP adapters can safely use that fact to choose 401 or 403 without
showing which permission, account, or resource failed.

## Voters and the decision manager

The authorization decision manager is separate from Symfony's firewall decision manager.
It sees only services tagged `app.authorization.voter` and uses
`UnanimousStrategy(false)`:

- any deny vote denies;
- at least one grant and no deny grants;
- all voters abstaining denies.

The attribute passed to the manager is the selected voter class. Unrelated module voters
abstain because they support neither that attribute nor the message type. The selected
voter accepts only the internal `AuthorizationToken` and fails closed for unsupported
messages or actors. `CqrsPass` validates and injects the expected route and permission
metadata when it builds the supported container.

Each business module has one cohesive voter rather than one voter per action:

- `AuthenticatingVoter` applies account provisioning, password upgrade, self-identity,
  and account-existence support-read rules.
- `AuthorizingVoter` protects assignment, entitlement, role, and capability operations.
- `TaskTrackingVoter` combines Task permissions with live account and ownership rules.

Voter `supportsAttribute()` and `supportsType()` methods stay pure and database-free.
Decision code may use `QueryBus` for approved cross-module reads and the owning module's
Domain repository port for local state. Voters do not inject handlers, raw Messenger
buses, ORM/SQL adapters, or UI services.

## Global permissions and local business rules

Authorizing answers a narrow question:

> Does this subject UUID currently have this installed global permission?

It does not answer:

- whether the subject is a live account;
- whether the subject owns a Task;
- whether a Task exists;
- whether the current actor kind is suitable for an operation.

The business voter composes those facts. For example, an account reading a Task needs:

```text
Account actor
    AND account still exists
    AND global task_tracking.task.view permission
    AND Task exists
    AND Task has an owner
    AND Task owner equals actor account
```

The `tasks` operator follows a separately coded rule and can bypass account ownership.
That bypass is not a role and does not come from a permission grant.

### TaskTracking decision summary

| Action | Account actor | `tasks` operator |
| --- | --- | --- |
| Create | Must be a live account, create for self, and hold create permission | May create unowned or for any live account |
| List | Must be a live account, filter by self, and hold view permission | May list all Tasks or filter by any owner |
| Get | Must be a live account, hold view permission, and own the existing Task | May request any valid Task UUID |
| Complete | Must be a live account, hold complete permission, and own the existing Task | May request any valid Task UUID |

Missing, foreign, and unowned Tasks deny account actors. An operator may pass admission
for a valid UUID that is not present; the handler then returns its ordinary not-found
result.

## Nested support reads

Voters often need data from another module. They perform that check through a public
query instead of reading another module's tables.

For example, `TaskTrackingVoter` asks:

- `CheckAccountExistenceQuery` for live account state;
- `EvaluateSubjectEntitlementsQuery` for the required global permission.

When a voter dispatches a nested query, the nested `AuthorizationToken` receives
`supportRead=true`. The relevant voter accepts that specific support query because the
infrastructure proves it originated inside another authorization decision. A caller
cannot set this flag in a query DTO.

Authorization decisions are required to be read-only:

- nested support queries are allowed and still pass their own validation and voter;
- commands and events are rejected inside a decision;
- the actor remains pinned;
- a nested failure poisons the root invocation and command transaction, even if code
  catches the exception.

The runtime directly prevents command/event dispatch from a voter and prevents partial
work from committing after a failed decision. Dependency and architecture rules also
limit what voters may inject, but they are guardrails rather than a sandbox: a voter must
not hide a write or external side effect behind a Domain port.

## The Authorizing module

The Authorizing module owns generic role, assignment, capability, and entitlement APIs.
It treats subject UUIDs as opaque. It has no dependency on Authenticating or
TaskTracking, and it does not verify that a subject represents an account.

### Domain type map

The Domain directory groups types by business concept. Authorizing alone uses explicit
technical suffixes such as `Entity`, `ValueObject`, `Enum` and `Service`; do not generalize
that naming rule to other modules without a separate design decision. Assignment,
Capability and Role are reasons-to-change boundaries, not technical buckets or table-per-
folder mappings. Values, behavior, entities and repository contracts stay together when
they change for the same reason. Cross-concept validation and exceptions may stay at the
Domain root.

The suffixes make local roles explicit:

| Suffix | Authorizing meaning |
| --- | --- |
| `Entity` | Persisted Domain identity, either a rich lifecycle owner or a narrow natural-key relationship. |
| `ValueObject` | Immutable validated Domain data with no identity. |
| `Enum` | Closed backed Domain vocabulary. |
| `Service` | Stateless Domain operation/collaborator not owned by one entity or value object. |
| `Repository` | Domain persistence port implemented by Infrastructure. |
| `Validator`, `Exception` | Purpose-specific cross-concept rule/failure names where applicable. |

Do not turn suffixes into namespaces. There is no `Domain/Mapping`, `Domain/Entity`,
`Domain/ValueObject` or empty placeholder directory. The suffix identifies responsibility,
not behavioral richness: `RoleEntity` is rich while the three relationship entities are
intentionally narrow. Application's reserved `Command`, `Query`, `Result`, `Input` and
`Event` suffixes are a separate repository-wide contract.

The resulting map is:

| Concept | Type | Role |
| --- | --- | --- |
| `Assignment` | `AssignmentReferenceValueObject` | A role/permission `kind` and `key` reference used for stored rows and continuations. |
| `Assignment` | `AssignmentChangeValueObject` | One validated operation plus assignment reference. |
| `Assignment` | `AssignmentChangeCountsValueObject` | Added and removed row counts returned by the repository. |
| `Assignment` | `AssignmentRepository` | The Domain port for assignment changes, entitlement evaluation, and listing. Its implementation owns subject locking. |
| `Assignment` | `EntitlementCheckValueObject` | One subject UUID and permission pair for a bounded decision batch. |
| `Assignment` | `AssignmentKindEnum`, `AssignmentOperationEnum` | Closed role/permission and add/remove vocabularies. |
| `Assignment` | `RoleAssignmentEntity`, `PermissionGrantEntity` | Natural-key Doctrine entities for the two assignment tables. |
| `Capability` | `AuthorizationCatalogService` | The compiler-fed catalogue of installed capability descriptors. It validates permission additions and role permission sets, answers known-permission checks, and provides bounded capability listing. |
| `Capability` | `AuthorizingPermissionEnum` | The two permission keys owned by Authorizing. |
| `Role` | `RoleEntity` | The rich persisted role model owning creation, definition/revision and retirement lifecycle. |
| `Role` | `RolePermissionMembershipEntity` | Natural-key Doctrine entity for one role/permission membership. |
| `Role` | `RolePermissionSetValueObject` | A catalog-validated, sorted permission bundle passed when defining a role. |
| `Role` | `RoleRepository` | The Domain port for defining, reading, listing, and retiring roles. |
| Shared root | `AuthorizationSyntaxValidator` | Shared role, permission and label syntax validation. |
| Shared root | `InvalidAuthorizationInputException` | The fixed Domain exception for invalid management input or stale role operations. |

There is no technical Domain bucket, `ChangeSet`, `StoredAssignment` or Domain cursor. DBAL
repositories retain bounded SQL and persistence concerns, while `RoleEntity` owns role
lifecycle behavior and assignment value objects express the KISS mutation/list model.

### Application use cases

| Use case | Purpose | Required authority |
| --- | --- | --- |
| `ChangeSubjectAssignmentsCommand` | Atomically add/remove 1-100 role assignments or direct grants for one subject | `assignments` operator or account with `authorizing.manage` |
| `ListSubjectAssignmentsQuery` | Keyset-list role assignments and direct grants for one subject | `assignments` operator or account with `authorizing.manage` |
| `EvaluateSubjectEntitlementsQuery` | Evaluate 1-100 ordered subject/permission checks in one read | `assignments` operator or internal support read |
| `DefineRoleCommand` | Create a role, revise its label/bundle, or create an exact definition if absent | `catalogue` operator or account with `authorizing.catalogue.manage` |
| `GetRoleQuery` | Read one active or retired role | Same catalogue authority |
| `ListRolesQuery` | Keyset-list active and retired roles | Same catalogue authority |
| `RetireRoleCommand` | Irreversibly retire a role using its expected revision | Same catalogue authority |
| `ListAuthorizationCapabilitiesQuery` | Keyset-list the compiled capability catalogue without SQL | Same catalogue authority |

`EvaluateSubjectEntitlementsQuery` intentionally is not directly available to account
actors, even when they hold `authorizing.manage`. It is a raw decision API for the
`assignments` operator and trusted voter support reads.

`authorizing.manage` is unrestricted global assignment-delegation authority. Its holder
may change assignments for any subject UUID, including itself, and may grant any installed
permission or active role. It can therefore grant itself `authorizing.catalogue.manage`.
Likewise, `authorizing.catalogue.manage` may define roles containing any installed
permission. Grant both permissions only to subjects trusted to administer application
authority; neither permission is limited to a tenant, module, or another target subject.

### Effective permission calculation

A subject has an installed permission when either source grants it:

```text
active global role assignment
    -> active, non-retired role definition
    -> matching role-permission membership

OR

matching global direct permission grant
```

The sources are additive. There are no deny grants, wildcards, role hierarchy, implicit
administrator bypass, or authorization cache. A syntactically valid but uninstalled
permission returns false.

The entitlement repository evaluates a bounded batch in one SQL read over the two
assignment sources.

### Assignment changes

An assignment change has an `add` or `remove` operation and a `role` or `permission`
kind. New additions are global. Direct permission additions must use an installed
permission, and role additions must use an active persisted role.

Removal is deliberately more permissive. It may remove retired or unknown keys for an
orphan subject. Repeating an addition or removal is idempotent and is reported as
unchanged. Duplicate or conflicting natural keys reject the complete batch. Public
mutation inputs contain only `operation`, `kind`, `key`.

Each mutation owns one command transaction, takes a subject-scoped PostgreSQL advisory
lock, validates role additions under a shared role-catalogue lock, and uses bounded
set-based SQL. Repositories do not flush, commit, or retry.

### Roles

A role has:

- an immutable lowercase key;
- a human-readable label;
- a sorted, nonempty bundle of installed permissions;
- a revision;
- an optional irreversible retirement timestamp.

New roles start at revision 1. Updating the label or permission bundle requires the exact
current revision and increments it. Retirement also requires the exact revision,
increments it, and cannot be undone.

`createIfAbsent` creates a missing role, but accepts an existing role only when it is
active and exactly matches the requested label and permission bundle. It never silently
overwrites customization.

Roles may combine installed permissions from different modules. The system permits at
most 4096 active role-permission membership edges in total. Retired assignments remain
visible and removable, but grant nothing.

Setup defines these exact snapshots:

| Role | Permissions |
| --- | --- |
| `task_tracking.user` | The three Task permissions |
| `authorizing.administrator` | The two Authorizing permissions |
| `application.administrator` | All five currently installed permissions |

Setup defines roles; it does not assign them to subjects. A future permission is not
automatically added to any role, including `application.administrator`.

## Persistence model

The rewritten fresh baseline `Version20260915010000` creates exactly four tables:

| Entity | Table | Natural primary key |
| --- | --- | --- |
| `RoleEntity` | `authorizing_role` | `role_key` |
| `RolePermissionMembershipEntity` | `authorizing_role_permission` | `(role_key, permission_key)` |
| `RoleAssignmentEntity` | `authorizing_role_assignment` | `(subject_id, role_key)` |
| `PermissionGrantEntity` | `authorizing_permission_grant` | `(subject_id, permission_key)` |

`Version20260917010000` and `Version20260920020000` are deleted. There are no resource
assignment/grant or initial-binding tables, scope/resource columns, `account_id` columns
or synthetic assignment UUIDs. The model is fresh-template only: dispose and recreate an
intermediate database instead of adding compatibility migrations or fallback reads.

PHP and wire APIs use `subjectId`; physical storage uses `subject_id`. There is no
cross-module foreign key from a subject to an account. This allows generic assignments and
also means Authorizing alone cannot prove that a subject is a live account.

Assignment listing reads role assignments and direct grants in kind/key order with keyset
pagination. Rows contain only `kind`/`key`; cursor data adds the subject binding. Pages are
bounded to 1-100 rows, default 50. There are no totals, offsets or cross-page snapshots.

## Transactions, races, and performance

Command authorization runs inside the command-owned transaction. This gives the voter
and handler one command transaction boundary, but it does not serialize every possible
concurrent revocation, account deletion, or business-state change.

An authorization read is a snapshot. Work that has already passed admission may finish
while another transaction revokes its role or grant. Subsequent checks see the committed
revocation. Business invariants that require stronger serialization must be enforced by
the owning module's transaction and locking rules, not by assuming authorization is a
global lock.

The preserved business-read budgets are deliberately bounded and pass clean-model
verification:

| Decision | Expected business reads |
| --- | ---: |
| Raw entitlement batch | 1 |
| Account Task create | 2 |
| Account Task get | 3 |
| Account Task list | 3 |
| Account Task complete | 4 |
| Operator Task list | 1 |

There is no authorization cache and no per-Task entitlement loop. When extending a voter,
batch checks where possible and update the tested read budget deliberately.

## Events

Application events do not use `#[Authorize]`. `EventPolicyMiddleware` checks that an
event is in the compiler-generated event inventory and maintains the execution frame,
but it does not ask a voter for admission.

With synchronous delivery, listener code runs while the producer command still owns its
transaction and pinned actor. A listener-dispatched command therefore sees that actor and
joins the root transaction.

With asynchronous delivery, the queue row commits with the producer transaction, but the
actor is not serialized. The worker receives the event as anonymous. Each command
dispatched by an async listener goes through ordinary authorization and owns its own
transaction. Design listener workflows so they do not depend on publisher authority.

## Adding or changing authorization

Use this sequence for a new command/query.

### 1. Choose the declaration

Choose exactly one:

- permission plus context for an assignable business capability;
- context only for a special actor/message rule;
- public only when anonymous execution is intentionally safe.

Put the attribute on the handler class, not on a method.

### 2. Add a permission when needed

Add a string-backed case to the owning module's Domain `*Permission` or `*PermissionEnum`
enum. Use a stable, module-prefixed key such as `task_tracking.task.archive`. Add an explicit stable label to
the handler declaration.

If several operations share a permission, they must use the same label. The compiler
will group their operation names into one capability.

Do not assume the new permission belongs in an existing setup role. Role snapshots must
be changed separately and intentionally.

### 3. Extend the module voter

Add the exact message rule to the existing cohesive module voter. Verify the
compiler-injected permission for permission-bearing routes, accept only
`AuthorizationToken`, and deny unknown messages or malformed state.

Keep the complete rule in the voter:

- actor kind and operator scope;
- required global permission;
- live account checks;
- ownership or other local state;
- any deliberate operator bypass limits.

Use `QueryBus` for another module's public support query and a Domain repository port for
state owned by the voter module.

### 4. Wire only a new module voter

When introducing the first voter for a module, follow the existing service pattern. The
voter must be private, lazy, autowired, not autoconfigured, and have exactly the
`app.authorization.voter` tag. Leave its route/permission-map constructor argument empty;
`CqrsPass` fills it.

Do not tag it `security.voter`. Business bus voters must not join Symfony's firewall
decision manager.

### 5. Test the decision, not the implementation

Cover the relevant matrix:

- correct and wrong actor kinds;
- correct and wrong operator scopes;
- granted, missing, and unknown permissions;
- live and deleted accounts;
- owned, foreign, unowned, and missing resources;
- malformed identifiers;
- the exact limits of an operator bypass;
- caught nested failures and transaction rollback where applicable;
- all actor kinds for a public action;
- query counts and N+1 behavior.

Run focused tests first, then `./bin/dev check`, applicable PostgreSQL E2E journeys with
`./bin/dev test`, and fresh-consumer verification when the template contract changes.

## Common mistakes

| Mistake | Correct model |
| --- | --- |
| Trusting `accountId` from a command as the caller | The trusted actor comes from `ExecutionContext`; payload IDs are only requested data. |
| Checking only a global permission | The module voter must also apply account, ownership, and message context. |
| Treating `application.administrator` as root | It is only an explicit permission bundle; contextual rules still apply. |
| Using Symfony roles for business permissions | Business permissions come from Authorizing role assignments and direct grants. |
| Calling a handler or voter directly | Dispatch through `CommandBus` or `QueryBus`; voters are private. |
| Dispatching a command/event inside a voter | Authorization decisions permit guarded support queries only. |
| Catching a nested authorization failure and continuing | The root invocation and transaction are already poisoned. |
| Assuming a public action is a public URL | Bus admission, route exposure, and firewall access are separate. |
| Assuming workers inherit publisher authority | Async events do not serialize the actor; workers begin anonymous. |
| Adding a permission and expecting roles to update | Runtime roles are explicit snapshots and never gain future permissions automatically. |
| Adding resource grants | Resource assignment/grant schema and APIs do not exist in the clean model. |

## Useful code map

| Concern | Main location |
| --- | --- |
| Handler declaration | `src/Platform/Authorization/Authorize.php` |
| Actor and kinds | `src/Platform/Authorization/Actor.php`, `ActorKind.php` |
| HTTP actor and frame pinning | `src/Platform/Authorization/ExecutionContext.php` |
| Bus admission | `src/Platform/Authorization/AuthorizationMiddleware.php` |
| Internal token | `src/Platform/Authorization/AuthorizationToken.php` |
| Public bus admission | `src/Platform/Authorization/PublicAccessVoter.php` |
| Compiler validation and generated maps | `src/Platform/Architecture/CqrsPass.php` |
| Installed capability catalogue | `src/Module/Authorizing/Domain/Capability/AuthorizationCatalogService.php` |
| Middleware order | `config/packages/messenger.yaml` |
| Private decision manager | `config/services/messaging.yaml` |
| Authenticating rules | `src/Module/Authenticating/Infrastructure/Framework/Symfony/Security/AuthenticatingVoter.php` |
| Authorizing rules | `src/Module/Authorizing/Infrastructure/Framework/Symfony/Security/AuthorizingVoter.php` |
| Task rules | `src/Module/TaskTracking/Infrastructure/Framework/Symfony/Security/TaskTrackingVoter.php` |
| Permission/assignment SQL | `src/Module/Authorizing/Infrastructure/Persistence/DoctrineAssignmentRepository.php` |
| Runtime role SQL | `src/Module/Authorizing/Infrastructure/Persistence/DoctrineRoleRepository.php` |
| Operator commands | `src/Module/Authorizing/UI/Console/AuthorizationConsole.php` |

For exact operator command syntax, see
[Authorizing operator management](../README.md#authorizing-operator-management). For the
formal module and transaction boundaries, see
[Architecture](architecture.md#authorization-and-runtime-roles-current-rework).
