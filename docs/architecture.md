# Approved architecture

## Purpose and baseline

A reusable template for multiple applications, with AI-assisted coding guidance,
repeatable checks and explicit human approval gates. MIT license, DonMario.
Single tenancy by default. Linux-first Docker Compose development/testing.
Symfony 8.1 initialized with Symfony CLI, PostgreSQL, Twig/AssetMapper, HTMX when
useful, and API Platform for application APIs. Exact dependencies live in the locks.

## Modules and data ownership

Initial business modules are **Authenticating**, **Authorizing** and
**TaskTracking**. Names end in `-ing` and describe the responsibility.

```text
src/Module/<Module>/
  Application/<UseCase>/
    <Name>Command.php or <Name>Query.php
    <Name>Handler.php
    <Name>Result.php                  # when a result DTO is useful
  Contract/Event/
  Domain/
  Infrastructure/
  UI/{Http,Api,Console,Event}/
  Resources/{config,templates,migrations}/
```

Create directories as needed. Modules are PHP namespaces, with module-owned
entities, repositories, use cases and migrations. Doctrine attributes are acceptable
on module models. Start with one database/default connection/EntityManager and an
ordered migration workflow. Platform health probing may use a dedicated technical
connection, independent of business transactions.

Cross-module code depends only on public command/query/result/event data. Commands,
queries and results live beside their handlers in Application use-case folders.
Public events live directly in `Contract/Event`. Public data contains no entities,
repositories, handlers or callable facades.
No cross-module foreign keys, ORM relationships, direct table reads/writes or SQL
joins. Opaque identifiers may reference other modules' concepts.

Dependency rules, container checks, metadata and actual schema checks enforce
the supported boundaries described below. SQL ownership needs additional targeted runtime tests and review;
import rules alone cannot prove arbitrary SQL ownership. Framework wiring and
technical transaction coordination have explicit, narrow exceptions.

### Application use cases and public data

On **2026-09-11**, the user chose use-case co-location and retained the public data
API for cross-module communication. This supersedes `Contract/Command`,
`Contract/Query` and `Contract/Result` placement. "Public contract" describes a role;
it does not require a `Contract` directory. See [Subtask 3](tasks/03-cqrs-transactions.md)
for approval, implementation and verification status.

```text
TaskTracking/Application/CreateTask/
  CreateTaskCommand.php
  CreateTaskHandler.php
TaskTracking/Application/GetTask/
  GetTaskQuery.php
  GetTaskHandler.php
  GetTaskResult.php
```

This is the intended CQRS example; Subtask 3a establishes its boundary rules with
executable fixtures. The business handlers and Messenger integration are Subtask 3b.

- The supported public Application data path is exactly
  `Application/<UseCase>/<Name>{Command,Query,Result}.php`. UseCase and Name are
  descriptive PascalCase identifiers; Name has a nonempty prefix before its suffix.
  Command/Query/Result suffixes are reserved for data within Application, including
  when checking misplaced classes. Other Application classes remain module-internal.
- Public data can be imported by another module's Application or adapters. Requests
  go through the appropriate bus; consumers never inject/call another module's handler.
  A public message is an integration API, not an authorization grant.
- Public DTOs follow the data-only rules below. Handlers are private services;
  messages, results and events are ordinary data objects excluded from service discovery.
- Domain has no dependency on Application DTOs or handlers. Application maps domain
  values/entities into public results. Domain may use public event data; event data
  may reference only approved immutable values and other public event data, keeping
  Application dependencies out of that path.
- A dedicated result DTO is optional. The proposed create use case returns `Uuid`;
  lookup returns `GetTaskResult|null` with UUID/title. Result data is transport-neutral:
  HTTP and CLI adapters decide status codes, serialization and presentation.

### Repository ports and inward dependencies

Each module defines repository interfaces in its Domain and Doctrine-specific
implementations in `Infrastructure/Persistence`. Symfony aliases bind these ports
to adapters; application handlers inject the Domain interface. The first port is
`TaskTracking\Domain\TaskRepository` (`add(Task): void`, `find(Uuid): ?Task`),
implemented by `Infrastructure\Persistence\DoctrineTaskRepository` through
EntityManager composition. `add()` does not flush/commit. Application transaction
coordination is introduced in Subtask 3. Repository ports are internal to the module,
separate from public Application message/result data and `Contract/Event` data.

Domain depends on its own domain types and public event data, not Application,
Infrastructure, UI or migration code. Application may use its Domain and public
contracts, not concrete Infrastructure/UI implementations. Infrastructure implements
domain ports. Runtime Doctrine/PDO types are prohibited in Domain/Application.
The documented mapping exception permits Doctrine ORM mapping attributes and the
Symfony Doctrine UUID type on Domain models, alongside Symfony UUID value objects;
entities do not name concrete infrastructure repositories in their attributes.
The mapping allowance lists exact mapping declaration classes (including the
singular AttributeOverride/AssociationOverride values, plus the `ORM` namespace
import), not the whole Mapping namespace; metadata factories/classes and mapping
drivers remain runtime-persistence dependencies.

### Implemented boundary checks (Subtasks 2 and 3a)

- **Source/Deptrac:** every first-party class has an owned namespace/path. Domain,
  Application implementation, public Application data and public event data have
  separate, non-overlapping layers. Module adapter layers may use their own
  internals and public data. Public Application data may reference approved immutable
  values and declared public data; event data may reference only approved immutable
  values and public events. Public data cannot reference any module's internals,
  including neighboring handlers. Domain cannot import public Application data.
  Platform is technical
  infrastructure: modules cannot use it as a cross-module facade. `App\Kernel`
  has the narrow boot-wiring exception. Fixture modules prove discovery without
  editing the checker configuration.
  Module configuration uses YAML. Classless PHP configuration is rejected because
  its executable code is outside the selected class-level dependency graph.
- **Contract data:** DTOs are final readonly classes with public typed promoted
  properties, empty constructors and scalar/null literal defaults. Data enums have
  literal int/string cases. Allowed types are scalars/null (including nullable and
  union forms), declared public data (event-only for event payloads),
  `DateTimeImmutable` and Symfony `Uuid`.
  Collections, callbacks, interfaces, inheritance, traits, attributes and additional
  behavior need a separately designed extension of this initial contract policy.
  `Platform/Architecture/ContractTypes` supplies the shared namespace/role vocabulary
  used by the source, Deptrac and container checks. Obsolete command/query/result
  contract paths and misplaced Application data suffixes fail source validation.
  These are structural restrictions, not universal deep-immutability analysis.
- **Container:** filesystem inventory validates module imports/registered concrete
  services and private/autowired/autoconfigured defaults before Symfony exposes
  controllers. Public data is excluded from the required-service inventory;
  explicit registration of message/result/event data fails before autowiring.
  The late service pass also rejects data definitions, including inline wiring.
  Neighboring handlers remain required services, and the Application data exclusions
  do not exclude `UI/Console/*Command` adapters. After autowiring and alias resolution,
  before removal/inlining, the
  boundary pass checks reference-bearing arguments, properties, calls, factories,
  configurators, inline definitions and module-consumed Symfony locators. Ordinary
  services are not recursively traversed through the whole framework graph.
  Standard default EntityManager/registry wiring is allowed; their aggregate
  repository factories/locators are not module-facing providers. The exact generated
  subscriber-locator diagnostic context is allowed without exposing the container
  to the subscriber. The exact standard `parameter_bag`/`ContainerBag` service
  exposes parameters, not services, and is allowed in controller locators.
  Direct Platform-to-module service edges and module-to-Platform facades are
  forbidden even through named aliases. Defaults apply to explicitly registered
  Domain services as well as the automatically discovered service layers.
  Unsupported expressions/computed references fail explicitly.
  For Domain/Application-to-Infrastructure edges, the named implementation must
  implement the same-module Domain interface declared on the direct constructor,
  property or configured method injection. This uses reflection on the injection
  site because Symfony alias resolution erases reference type IDs. Untyped/mixed
  references cannot claim a port merely by naming its alias. Port types are not
  inferred from arrays, generic locators, factory arguments or inline adapters;
  those need explicit reviewed wiring rather than an implicit exception.
  Inline module definitions are checked in their own layer; transparent vendor
  definitions/locators retain the consumer context. Reflection matches both
  positional and named arguments, including names introduced by Symfony after
  skipping complex default values.
- **Metadata/schema:** all attribute entities found in module Domain directories
  must be mapped. Entity/repository, association, inheritance, embeddable, table and
  join-table ownership are checked offline. Actual PostgreSQL public tables and
  outgoing foreign keys are inspected without Doctrine asset filters hiding
  unexpected tables. Mapping/schema drift fails. Only the exact
  `public.doctrine_migration_versions` table is exempt from entity mapping.
  Public views/materialized views/foreign tables are explicitly unsupported;
  other schemas and standalone sequences are outside the ownership audit.
- **Migrations:** module file/path inventory, valid unique UTC timestamps and
  transactional flags are validated before execution. A Doctrine comparator sorts
  pending versions by timestamp across module namespaces. One default EntityManager
  and per-migration transactions are used; setup applies pending checked-in changes.

Tests establish normal Doctrine wiring, permitted public data, omitted registrations,
alias/locator bypass rejection, metadata violations, raw cross-module foreign keys,
actual migration failure rollback/recovery and persisted ORM data across database
container recreation. These checks are build/verification-time guardrails, not a
sandbox: all modules use the same database role. Runtime lookup/factory bodies,
dynamic SQL and which module a migration's SQL actually changes require targeted
runtime tests and review. No schema introspection runs on ordinary requests.

## CQRS and events

Subtasks 1, 2 and 3a are accepted. The concrete command/query implementation is
proposed in [Subtask 3b](tasks/03-cqrs-transactions.md#3b--proposed-design-awaiting-approval)
and awaits design/implementation approval. The bus, validation and transaction
rules below describe the architectural direction, not installed runtime behavior.

Symfony Messenger provides separate command/query/event buses, with minimal result
wrappers if needed. Commands/queries are messages with exactly one handler; use
cases execute in handlers and invariants stay in domain models. Queries do not
change business state. Thin web/API/console/event adapters use these buses.

Events have zero or more listeners. Listeners dispatch commands/queries rather
than executing business logic themselves. Synchronous events are the default:
flush required writes, publish inside the transaction, then commit. Subscriber
failure rolls back participating database effects. This order requires actual tests.

The optional asynchronous mode uses Messenger's PostgreSQL-backed transport with
enqueueing through the **same transactional connection** as the business change.
Receiving commands commit independently. Retries require idempotency keyed by
effect/subscription and event ID, committed with the effect. Include failure
transport, replay, crash/duplicate tests and compatibility/retention guidance.
Async changes consistency and failure semantics; it is not an event archive or
exactly-once/global-order guarantee. Mandatory invariants stay synchronous.

## Authenticating and Authorizing

Authenticating initially provides CLI account provisioning, email/password web
login/logout, and Symfony Security integration. Structure it so self-service and
OIDC can add adapters/use cases later. Safe principal identities cross boundaries.

Twig/HTMX web routes use sessions and CSRF protection. **The SPA-facing API uses
stateless bearer JWTs, shipped in the initial template.** The web session cannot
authenticate API requests. Token issuance and an API Platform identity endpoint
must be tested. JWT lifecycle, renewal, logout/revocation, account-disable behavior,
issuer/audience, rotation and browser handling are explicit JWT-subtask decisions.
Keys are application-specific and generated locally. LexikJWTAuthenticationBundle
is the proposed maintained integration, subject to dependency/runtime verification.

Authorizing owns code-defined permission/role bundles, persisted assignments and
direct global/resource grants. Deny by default. Effective permissions are the union
of applicable grants and roles. Removing the last applicable source denies future
checks after commit; already-authorized operations may finish. JWT claims/session
roles do not become a stale second authority for business permissions.

Enforce permissions on use-case paths for every entry point. Actors and restricted
internal capabilities come from trusted infrastructure, never caller-controlled
privilege flags. Grant/revoke commands require authority. Domain modules retain
ownership rules and invariants. Bulk permission queries and bounded pagination
avoid cross-module SQL; cursor pages can be underfilled and omit exact totals.

## Adapters, cache and demo

API Platform DTO providers call queries; processors call commands. Twig, CLI and
API share use cases. OpenAPI and explicit API contracts provide the SPA seam.

Symfony filesystem cache is the baseline. A later tested Valkey configuration
switches declared application pools. Cache keys, invalidation and post-commit
behavior must preserve authorization and avoid publishing rolled-back data.
Session/throttling storage is configured deliberately rather than silently switched.

TaskTracking demonstrates create/list/complete use cases and a real invariant,
permissions, and completion activity through an event-triggered command. Required
initial owner access is established synchronously. Optional activity can be async.

## Reuse and verification

Clean template exports initialize independent identity, secrets, Compose resources
and cookie/key namespaces. Initialization is non-destructive on reruns. Demo omission
is initially supported before database initialization; removal from an installed
application requires a migration design.

Every subtask has discovery/design approval, security/performance review, actual
E2E evidence, a fresh independent reviewer and approval before continuation.
Security/performance bounds are feature-specific and executable where practical.
