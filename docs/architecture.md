# Approved architecture

## Purpose and baseline

A reusable template for multiple applications, with AI-assisted coding guidance,
repeatable checks and explicit human approval gates. MIT license, DonMario.
Single tenancy by default. Linux-first Docker Compose development/testing.
Symfony 8.1 initialized with Symfony CLI, PostgreSQL, Twig/AssetMapper, HTMX when
useful, and API Platform for application APIs. Exact dependencies live in the locks.

Subtasks 1, 2, 3a, 3b and 4 are accepted. The revised [Subtask 4](tasks/04-synchronous-events.md)
design, including opt-in recording, is implemented, fully verified, independently
reviewed and accepted by the user. Next is separate Subtask 5 discovery/design.
Later features below remain approval-gated.

## Modules and data ownership

Initial business modules are **Authenticating**, **Authorizing** and
**TaskTracking**. Names end in `-ing` and describe the responsibility.

```text
src/Module/<Module>/
  Application/<UseCase>/
    <Name>Command.php or <Name>Query.php
    <Name>Handler.php
    <Name>Result.php                  # when a result DTO is useful
    <Name>Event.php                   # public Application integration fact
  Domain/
    Event/<Name>Event.php             # internal Domain fact
  Infrastructure/
    Event/<Name>Event.php             # our internal technical event
    EventListener/<Name>Listener.php  # private Application-event subscriber
    Framework/<Library>/EventListener/<Name>.php
  UI/{Http,Api,Console}/
  Resources/{config,templates,migrations}/
```

Create directories as needed. Modules are PHP namespaces, with module-owned
entities, repositories, use cases and migrations. Doctrine attributes are acceptable
on module models. Start with one database/default connection/EntityManager and an
ordered migration workflow. Platform health probing may use a dedicated technical
connection, independent of business transactions.

Cross-module code depends only on public command/query/result/event data. Commands,
queries, results and public events live in Application use-case folders. There is
no current `Contract` directory. Public data contains no entities,
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
for historical approval and evidence. Subtask 4 supersedes its `Contract/Event`
direction with public Application events and internal Domain/Infrastructure events.

```text
TaskTracking/Application/CreateTask/
  CreateTaskCommand.php
  CreateTaskHandler.php
  TaskCreatedEvent.php
TaskTracking/Application/GetTask/
  GetTaskQuery.php
  GetTaskHandler.php
  GetTaskResult.php
```

Subtask 3a establishes the boundary rules; Subtask 3b implements these business
handlers, Messenger integration and shared HTTP/CLI adapters.

- The supported public Application data path is exactly
  `Application/<UseCase>/<Name>{Command,Query,Result,Event}.php`. UseCase and Name are
  descriptive PascalCase identifiers; Name has a nonempty prefix before its suffix.
  Command/Query/Result/Event suffixes are reserved for data within Application, including
  when checking misplaced classes. Other Application classes remain module-internal.
- Public data can be imported by another module's Application or adapters. Requests
  go through the appropriate bus; consumers never inject/call another module's handler.
  A public message is an integration API, not an authorization grant.
- Public DTOs follow the data-only rules below. Handlers are private services;
  messages, results and events are ordinary data objects excluded from service discovery.
- Domain has no dependency on Application DTOs, public events or handlers. It records
  its own `Domain/Event/*Event` facts; Application explicitly translates selected
  facts to public events and maps domain values/entities into public results.
  Public event payloads may reference approved immutable values and concrete public
  event data, never command/query/result DTOs or module-internal types.
- A dedicated result DTO is optional. The create use case returns `Uuid`;
  lookup returns `GetTaskResult|null` with UUID/title. Result data is transport-neutral:
  HTTP and CLI adapters decide status codes, serialization and presentation.

### Repository ports and inward dependencies

Each module defines repository interfaces in its Domain and Doctrine-specific
implementations in `Infrastructure/Persistence`. Symfony aliases bind these ports
to adapters; application handlers inject the Domain interface. The first port is
`TaskTracking\Domain\TaskRepository` (`add(Task): void`, `find(Uuid): ?Task`),
implemented by `Infrastructure\Persistence\DoctrineTaskRepository` through
EntityManager composition. `add()` does not flush/commit. Application transaction
coordination is implemented in Subtask 3b. Repository ports are internal to the module,
separate from public Application message/result/event data.

Domain depends on its own domain types and internal Domain events, not Application,
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

### Implemented boundary checks (Subtasks 2, 3a, 3b and 4)

Subtask 4 extensions below, including opt-in recording, are verified, independently reviewed and user-accepted.

- **Source/Deptrac:** every first-party class has an owned namespace/path. Domain,
  Application implementation, public command/query/result data, public events,
  internal Domain/Infrastructure events, our listeners and framework listeners have
  separate, non-overlapping layers. Ordinary module adapter layers may use their own
  internals and public data; our event listeners have the narrower permissions below.
  Public command/query/result data may reference approved immutable
  values and declared public data; public event data may reference only approved immutable
  values and public events. Public data cannot reference any module's internals,
  including neighboring handlers. Domain cannot import public Application data.
  Application/UI may use the exact CommandBus/QueryBus helpers; Application alone
  may also use ApplicationEventRecorder. Our event listeners may use the two helpers.
  The exact event-category primitives are pure-data inheritance exceptions, not
  Platform service access. Domain implementation also has the exact opt-in
  `Platform/Event/Recording/RecordsDomainEvents` interface and
  `RecordsDomainEventsTrait` permission described below, alongside DomainEvent.
  Deptrac also classifies transitive inheritance edges to BaseEvent; source checks
  still require the exact direct category parent and reject primitive payload types.
  Other Platform types cannot be module-facing facades. `App\Kernel`
  has the narrow boot-wiring exception. Fixture modules prove discovery without
  editing the checker configuration.
  Module configuration uses YAML. Classless PHP configuration is rejected because
  its executable code is outside the selected class-level dependency graph.
  `SourceRules` also rejects statically named cross-listener dependencies/calls with
  `event.listener_dependency`, including listeners in the same layer, whose edges
  Deptrac ignores. This complements the existing DI rejection; it is not dynamic
  callable-body analysis.
- **Contract data:** DTOs are final readonly classes with public typed promoted
  properties, empty constructors and scalar/null literal defaults. Command/query/result data enums have
  literal int/string cases. Allowed types are scalars/null (including nullable and
  union forms), declared public data (concrete public events only for public event payloads),
  `DateTimeImmutable` and Symfony `Uuid`.
  Internal Domain/Infrastructure event payloads allow only scalar/null and those
  immutable values. Concrete events directly extend their exact category primitive;
  commands/queries/results retain the no-inheritance rule. Collections, callbacks,
  interfaces, traits, attributes and additional behavior remain forbidden in DTOs.
  `Platform/Architecture/ContractTypes` supplies the shared namespace/role vocabulary
  used by the source, Deptrac and container checks. All obsolete `Contract` paths,
  misplaced data suffixes, wrong categories and intermediate event bases fail source
  validation. Primitives cannot be used as broad public payload types.
  These are structural restrictions, not universal deep-immutability analysis.
- **Container:** filesystem inventory validates module imports/registered concrete
  services and private/autowired/autoconfigured defaults before Symfony exposes
  controllers. Public data, internal events and primitives are excluded from services;
  explicit registration of message/result/event data fails before autowiring.
  The late service pass also rejects data definitions, including inline wiring.
  The recording interface/trait are separately classified support, not public data
  or event primitives. Both passes reject their service definitions, including
  inline and noncanonical registrations; implementing Domain entities retain their
  normal internal classification.
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
  Direct Platform-to-module service edges and arbitrary module-to-Platform facades
  are forbidden even through named aliases, apart from the exact permissions above.
  ApplicationEventRecorder is Application-only; our private event listeners may
  inject CommandBus/QueryBus but no repositories, handlers, ORM or raw buses, even
  from their own module. Framework listeners do not inherit these permissions.
  The sole listener-invocation exception admits the exact descriptor-owned inline
  EventListenerInvoker, ServiceClosureArgument and validated listener target created
  by CqrsPass. ModuleServicesPass validates that shape; it grants no general
  Platform-to-module service permission.
  Inline substitute facades and raw Messenger services are rejected. Defaults apply to explicitly registered
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

Subtasks 1, 2, 3a and 3b are accepted. Subtask 3b implements separate synchronous
`command.bus` and `query.bus` with Messenger 8.1.6 and Validator 8.1.6. Its completed
verification/review evidence and user acceptance are in [the task record](tasks/03-cqrs-transactions.md).

### Implemented synchronous CQRS (3b)

- Application/UI use the exact `Platform/Messaging/CommandBus` and `QueryBus`
  helpers. Public messages have no envelopes, stamps or caller-selected validation
  groups. Their known message kind is compiled from the 3a public-data inventory.
- `CqrsPass` runs after Messenger/autowiring and before service removal. It requires
  one effective handler per command/query on the correct bus, same-module/use-case co-location, one
  exact DTO argument and a declared public/value-data return type. Public handler
  definitions and public aliases (including chains and same-class copies) are
  rejected. Wildcard, union,
  transport-specific, batch, indirect/factory and missing/duplicate registrations
  fail the supported policy. This inventory is not arbitrary callable-body analysis.
- Scope/message policy, bus-name stamp, standard validation, command transaction
  (commands only) and standard handling form the explicit middleware order. The pass
  checks standard service classes, shared invocation state, references and helper
  wiring. Checked buses, middleware, locators and handler descriptors cannot be
  reinitialized through configured method calls; the sole setter exception is the
  standard handling middleware's logger reference. Debug may add only Symfony's
  standard leading tracing middleware.
- Standard Redispatch, early-cache-expiration, RunCommand, RunProcess and PingWebhook
  message registrations are recognized as framework-only. They cannot match a
  final readonly application message, and runtime message policy rejects them.
- Module YAML validation is explicitly registered. The current title and UUID
  constraints are verified against actual metadata and both adapters. Missing YAML
  mapping is exercised by a negative fixture; this is not universal constraint
  completeness inference for future use cases.
- Invocation state starts before validation without opening the database. A valid
  outer command owns the default connection transaction via ORM `wrapInTransaction`.
  Repositories/handlers schedule writes; the boundary checks the handled result and
  failure state, flushes and commits. Nested commands neither flush nor commit
  independently. Any nested command/query exception prevents outer commit even if
  the caller catches it. Only the outer result indicates a completed commit.
- Definite failure invalidates ORM state and closes failed DBAL connection state;
  independent root operations reset the native-lazy EntityManager and invocation
  context. Tests prove held repository/manager references work after failure.
  Externally opened DBAL transactions are rejected rather than adopted.
- Queries do not automatically flush and cannot dispatch commands. Nested queries
  preserve the parent's unit of work; independent queries discard managed state.
  Query purity against arbitrary direct SQL remains a convention with targeted
  tests/review, not a PostgreSQL read-only sandbox. Lost connectivity during commit
  can mean an unknown outcome, and commands are not automatically retried.
- Dev/test-only JSON routes and CLI adapters share create/read handlers. HTTP input
  is bounded to 4 KiB and fixed fields; title length is at most 200 codepoints.
  Validation errors expose field/message diagnostics; unexpected errors use safe
  operation metadata and generic responses. Production route absence is tested.

### Accepted layered events (4)

The user approved revised events and **category-only primitives**. This supersedes
the historical `Contract/Event` and precommit-publication direction; that direction
in earlier task evidence is not the current contract.

`Platform/Event/BaseEvent` is an empty abstract readonly class. Its direct children
`DomainEvent`, `ApplicationEvent` and `InfrastructureEvent` are also empty abstract
readonly classes: no constructors, state, behavior, event IDs, timestamps or delivery
metadata. Every concrete event is final readonly and directly extends the category
matching its exact path above. Domain/Infrastructure events stay internal; only
inventoried concrete public Application events enter `application.event.bus`.

Our private `Infrastructure/EventListener/*Listener` services have one class-level
`#[AsMessageHandler(bus: 'application.event.bus')]`, optionally with integer priority,
and public non-static `__invoke(ExactApplicationEvent): void`. Zero or multiple distinct
listeners are valid. Dependencies are restricted to public data, approved values,
the handler attribute and exact command/query helpers. Vendor event adapters belong
in `Infrastructure/Framework/<Library>/EventListener`; their events retain vendor
ownership and are not automatically Application-bus messages. Compilation reconciles
source declarations with effective registrations, privacy and exact runtime wiring.

After validating the original listener descriptors, CqrsPass wraps each in an inline
`EventListenerInvoker` with a `ServiceClosureArgument`. Listener resolution and
construction then occur inside Messenger's per-handler catch, so those failures also
allow remaining listeners to run. Diagnostics retain the safe logical listener
identity rather than exposing generated wrapper details. This narrowly validated
wiring preserves listener privacy and the module service boundaries above.

Required actions use **explicit command orchestration inside the root transaction**.
Nested failures invalidate the root even when caught; handlers/repositories never
flush. Events are optional integration facts, not required invariants or authorization
grants. The delivery lifecycle is:

1. `Task` opts into `RecordsDomainEvents` and `RecordsDomainEventsTrait`, recording
   an internal Domain TaskCreatedEvent through protected `recordDomainEvent()`.
   The trait holds a private transient `list<DomainEvent>`; public `releaseEvents()`
   returns and clears the batch. These two pure support types live under
   `Platform/Event/Recording`, have no ORM/bus dependencies and are available only
   to Domain implementation. Application calls the entity's release method without
   importing the support types. Collection is explicit, not automatic or durable;
   the runtime delivery caps do not bound entity-local buffers. `CreateTaskHandler` schedules
   persistence through its Domain port, releases the facts and explicitly maps them
   only Domain TaskCreatedEvent to `Application/CreateTask/TaskCreatedEvent(Uuid $taskId)` using
   `ApplicationEventRecorder::record(object)`. There is no generic aggregate scan.
   The recording capability does not require a universal entity/aggregate base.
   Identity remains module-local; optimistic locking is a separate future update
   use-case/concurrency design. The four event-category primitives remain empty.
2. Recording requires a healthy owned command-handler scope, with no enclosing query.
   Invalid kind/scope use is a programming error and invalidates an active root.
   Recording or messaging from ORM lifecycle callbacks is forbidden. Current detection
   inspects stack frames for Doctrine ListenersInvoker/EventManager dispatch, alongside
   the handler-phase guard. This is a bounded coverage claim for those ORM paths, not
   an arbitrary callback sandbox or a fixed-cost stack scan.
3. ORM `wrapInTransaction()` remains the physical transaction owner. Result/health
   checks precede its implicit flush/commit. Context failure also marks an active
   DBAL transaction rollback-only, so caught violations during flush cannot commit.
   Definite failure or unconfirmed commit discards the pending batch.
   Real PostgreSQL tests execute actual `postFlush` callbacks that catch recording
   and command-dispatch rejections: rollback-only still prevents commit, and held
   services recover for subsequent operations. This proves those lifecycle paths,
   not an arbitrary callback sandbox.
4. Only after confirmed commit and root ORM/context cleanup does a separate delivery
   session drain events synchronously. Each listener-dispatched command starts a
   fresh independent root transaction and cleanup cycle. The private event bus uses
   delivery/exact-message policy, bus-name stamp and standard zero-or-more handling.
5. Delivery is FIFO across batches: with producer A/B and A producing C, order is
   A/B/C, without recursive event dispatch. Messenger attempts all current-event
   listeners; failures are diagnosed and remaining listeners/events continue. A
   successful listener command's events survive a later listener exception; failed
   command batches are discarded without undoing other committed effects.
6. Producer success is retained, including HTTP201/UUID/Location and CLI UUID/exit0.
   Subscriber validation errors do not become producer input errors. Safe diagnostics
   include event/listener identity, exception class and dropped/discarded counts,
   never payloads, exception messages/objects, SQL or credentials. Logging is itself
   best effort and cannot replace a committed result.

At most **100 events per root buffer** and **100 accepted events per delivery session**
are retained; the latter includes delivered and zero-listener events. Valid overflow
is dropped with a summarized diagnostic, without rolling back business work. Invalid
recording remains an error. Counts bound cascades, not payload bytes or arbitrary
handler runtime. Producer locks are released before delivery; listener transactions
still add latency before the original response. State assumes sequential execution,
not concurrent fibers sharing a container.

If ORM cleanup fails after commit, preserve the committed result, skip that batch's
unsafe delivery and disable further command/query/recording work on the contaminated
runtime. Replace the runtime before resuming. Process death/OOM can lose queued events
or the response. COMMIT connectivity loss can leave an unknown outcome; commands are
not automatically retried. Only each command's participating database writes are
atomic; external effects cannot be rolled back. There is **no outbox, durable delivery,
retry, replay or deduplication** in this subtask.

`EventObserving` is a disposable physical test-fixture module, not a production
business module. Its owned table, listener commands and independent SQL observations
establish postcommit visibility, partial failure, FIFO/bounds and recovery. Full
container/PostgreSQL/consumer verification passed; both fresh independent reviewers
approved the latest code and evidence after findings were resolved. See
[Subtask 4](tasks/04-synchronous-events.md) and the [handoff evidence](handoff.md#subtask-4-final-verification-and-review)
for completed verification/review and user acceptance, including the recording follow-up.

### Future durable/async delivery (5)

Outbox obligations and optional PostgreSQL-backed Messenger delivery require separate
design approval. Atomic persistence/enqueueing, per-subscription idempotency and
delivery identity, worker retries/failure transport, replay and crash/duplicate tests
must be designed together. Current category primitives provide none of that metadata.
Async is not an event archive or exactly-once/global-order guarantee. Required
invariants remain explicitly orchestrated commands.

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
initial owner access uses explicit transactional command orchestration. Optional
activity can use best-effort events or the separately designed durable delivery mode.

## Reuse and verification

Clean template exports initialize independent identity, secrets, Compose resources
and cookie/key namespaces. Initialization is non-destructive on reruns. Demo omission
is initially supported before database initialization; removal from an installed
application requires a migration design.

Every subtask has discovery/design approval, security/performance review, actual
E2E evidence, a fresh independent reviewer and approval before continuation.
Security/performance bounds are feature-specific and executable where practical.
