# Approved architecture

## Purpose and baseline

A reusable template for multiple applications, with AI-assisted coding guidance,
repeatable checks and explicit human approval gates. MIT license, DonMario.
Single tenancy by default. Linux-first Docker Compose development/testing.
Symfony 8.1 initialized with Symfony CLI, PostgreSQL, Twig/AssetMapper, HTMX when
useful, and API Platform for application APIs. Exact dependencies live in the locks.

Subtasks 1, 2, 3a, 3b and 4 are accepted. The delivery designs in historical
[Subtask 4](tasks/04-synchronous-events.md) and [Subtask 5](tasks/05-durable-events.md)
are **superseded by approved [Subtask 5b](tasks/05b-native-event-bus.md)**.
The native implementation is verified in containers/PostgreSQL and independently
reviewed with all findings resolved and accepted by the user on 2026-09-13. Later features
retain their own approval gates.

## Modules and data ownership

Planned business modules are **Authenticating**, **Authorizing** and
**TaskTracking**; TaskTracking is currently installed. Names end in `-ing` and
describe the responsibility.

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
the supported boundaries described below. SQL ownership needs additional targeted
runtime tests and review; import rules alone cannot prove arbitrary SQL ownership.
Framework wiring and technical transaction coordination have narrow exceptions.

### Application use cases and public data

Public data and handlers are co-located by use case:

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
handlers, Messenger integration and shared HTTP/CLI adapters. See
[Subtask 3](tasks/03-cqrs-transactions.md) for approval and evidence.

- The supported public Application data path is exactly
  `Application/<UseCase>/<Name>{Command,Query,Result,Event}.php`. UseCase and Name are
  descriptive PascalCase identifiers; Name has a nonempty prefix before its suffix.
  Command/Query/Result/Event suffixes are reserved for data within Application,
  including when checking misplaced classes. Other Application classes remain internal.
- Public data can be imported by another module's Application or adapters. Requests
  go through the appropriate bus; consumers never inject/call another module's handler.
  A public message is an integration API, not an authorization grant.
- Public DTOs follow the data-only rules below. Handlers are private services;
  messages, results and events are ordinary data objects excluded from discovery.
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
EntityManager composition. `add()` does not flush/commit. Repository ports are
internal to the module, separate from public Application message/result/event data.

Domain depends on its own domain types and internal Domain events, not Application,
Infrastructure, UI or migration code. Application may use its Domain and public
contracts, not concrete Infrastructure/UI implementations. Infrastructure implements
domain ports. Runtime Doctrine/PDO types are prohibited in Domain/Application.
The mapping exception permits Doctrine ORM mapping attributes and the Symfony
Doctrine UUID type on Domain models, alongside Symfony UUID value objects; entities
do not name concrete infrastructure repositories in their attributes.
The allowance lists exact mapping declaration classes (including singular
AttributeOverride/AssociationOverride values and the `ORM` namespace import), not
the whole Mapping namespace; metadata factories/classes and mapping drivers remain
runtime-persistence dependencies.

### Implemented boundary checks

- **Source/Deptrac:** every first-party class has an owned namespace/path. Domain,
  Application implementation, public command/query/result data, public events,
  internal Domain/Infrastructure events, our listeners and framework listeners have
  separate, non-overlapping layers. Ordinary module adapters may use their own
  internals and public data; our event listeners have narrower permissions.
  Public command/query/result data may reference approved immutable values and
  declared public data; public events may reference only approved immutable values
  and public events. Domain cannot import public Application data.
  Application/UI may use exact CommandBus/QueryBus helpers; Application alone may
  also use EventBus. Our event listeners may use CommandBus/QueryBus.
  Exact event categories are pure-data inheritance exceptions. Domain implementation
  additionally has exact `Platform/Event/Recording/RecordsDomainEvents` and
  `RecordsDomainEventsTrait` permissions, alongside DomainEvent. Deptrac classifies
  transitive inheritance to BaseEvent; source checks require the exact direct
  category parent and reject primitive payload types. Other Platform types cannot
  be module-facing facades. `App\Kernel` has a narrow boot-wiring exception.
  Module configuration is YAML; classless executable PHP configuration is rejected.
  `SourceRules` rejects statically named cross-listener dependencies/calls with
  `event.listener_dependency`, including same-layer edges ignored by Deptrac.
  This is not dynamic callable-body analysis.
- **Contract data:** DTOs are final readonly classes with public typed promoted
  properties, empty constructors and scalar/null literal defaults. Command/query/result
  enums have literal int/string cases. Allowed types are scalars/null (including
  nullable and union forms), declared public data (concrete public events only for
  event payloads), `DateTimeImmutable` and Symfony `Uuid`. Internal Domain/Infrastructure
  events allow only scalar/null and those immutable values. Concrete events directly
  extend their exact category; commands/queries/results have no inheritance.
  Collections, callbacks, interfaces, traits, attributes and additional behavior
  remain forbidden in DTOs. `Platform/Architecture/ContractTypes` supplies the shared
  namespace/role vocabulary. Obsolete `Contract` paths, misplaced data suffixes,
  wrong categories and intermediate event bases fail validation. These structural
  restrictions prove neither universal deep immutability nor serializer round-trips
  for every permitted type combination.
- **Container:** filesystem inventory validates module imports/registered concrete
  services and private/autowired/autoconfigured defaults before Symfony exposes
  controllers. Public data, internal events, category primitives and recording
  support are excluded from services. Explicit, inline and noncanonical data/support
  definitions are rejected; Domain entities implementing recording support retain
  their internal classification. Neighboring handlers remain required services;
  Application data exclusions do not exclude `UI/Console/*Command` adapters.
  After autowiring/alias resolution and before removal/inlining, the boundary pass
  checks reference-bearing arguments, properties, calls, factories, configurators,
  inline definitions and module-consumed Symfony locators. Ordinary services are not
  recursively traversed through the whole framework graph. Standard default
  EntityManager/registry wiring is allowed; aggregate repository factories/locators
  are not module-facing providers. The generated subscriber-locator diagnostic
  context and standard parameter-bag service have narrow allowances.
  Direct Platform-to-module edges and arbitrary module-to-Platform facades are
  forbidden, including named aliases, apart from the exact helper permissions.
  EventBus is Application-only. Our listeners cannot inject repositories, handlers,
  ORM or raw buses, even from their own module. Native Messenger owns handler lookup;
  framework listeners do not inherit our listener permissions. Inline substitute
  facades and raw Messenger services are rejected. Defaults also apply to explicitly
  registered Domain services; unsupported expressions/computed references fail.
  For Domain/Application-to-Infrastructure edges, the implementation must implement
  the same-module Domain interface declared on the direct constructor, property or
  configured method injection. Reflection checks the injection site after alias
  resolution, including positional/named arguments. Untyped/mixed references cannot
  claim a port by alias name. Port types are not inferred from arrays, generic
  locators, factory arguments or inline adapters. Inline module definitions retain
  their own layer; transparent vendor definitions/locators retain consumer context.
- **Metadata/schema:** all attribute entities in module Domain directories must be
  mapped. Entity/repository, association, inheritance, embeddable, table and join-table
  ownership are checked offline. Actual PostgreSQL public tables and outgoing foreign
  keys are inspected without Doctrine asset filters hiding unexpected tables.
  Mapping/schema drift fails. The exact `public.doctrine_migration_versions` table
  is exempt from entity mapping; the exact `public.platform_messaging_message`
  transport table is separately checked as described below. This is not a `platform_*`
  exemption. Public views/materialized views/foreign tables are unsupported; other
  schemas and standalone sequences are outside the ownership audit.
- **Migrations:** module file/path inventory, valid unique UTC timestamps and
  transactional flags are validated before execution. A comparator sorts pending
  versions chronologically across module namespaces and the exact Platform Messaging
  path/namespace. One default EntityManager and per-migration transactions are used;
  setup applies pending checked-in changes without resetting data.

Tests cover ordinary wiring, permitted public data, omitted registrations,
alias/locator bypass rejection, metadata violations, raw cross-module foreign keys,
migration rollback/recovery and persisted ORM data across database recreation.
These are build-time guardrails, not a sandbox: modules share a database role.
Runtime lookup/factory bodies, dynamic SQL and migration SQL ownership require
targeted tests/review. No schema introspection runs on ordinary requests.

## CQRS and events

### Synchronous CQRS

Separate synchronous `command.bus` and `query.bus` use Messenger and Validator 8.1.6.
[Subtask 3b](tasks/03-cqrs-transactions.md) records accepted verification and review.

- Application/UI use exact `Platform/Messaging/CommandBus` and `QueryBus` helpers.
  Public messages expose no envelopes, stamps or caller-selected validation groups.
  Known message kinds come from the compiled public-data inventory.
- `CqrsPass` runs after Messenger/autowiring and before service removal. It requires
  one effective handler per command/query on its correct bus, same-module/use-case
  co-location, one exact DTO argument and a declared public/value-data return type.
  Public handlers/aliases, wildcard, union, transport-specific, batch, indirect/factory
  and missing/duplicate application registrations fail the supported policy.
- Command/query middleware order is explicit: invocation scope, message policy,
  bus-name stamp, standard validation, command transaction (commands only), handling.
  Checks cover these service classes, helper references and shared invocation wiring;
  standard handling's logger setter and leading debug tracing are supported.
  Native Symfony message registrations remain framework-owned; runtime policy admits
  only inventoried application messages. These checks do not exhaustively validate
  the vendor service graph or arbitrary callable bodies.
- Module YAML validation must be explicitly registered. Title/UUID constraints and
  missing-mapping rejection are tested; future constraint completeness needs review.
- Invocation state starts before validation without opening the database. A valid
  outer command owns the default connection transaction through ORM `wrapInTransaction`.
  Repositories/handlers schedule writes; the boundary checks result/health before
  implicit flush/commit. Nested commands do not flush or commit independently.
  Nested command/query failures and EventBus dispatch failures invalidate the root
  even if caught. Context failure marks an active DBAL transaction rollback-only.
- Definite failure invalidates ORM state and closes failed DBAL connection state.
  Independent roots reset the native-lazy EntityManager and invocation context;
  held repository/manager recovery is tested. Externally opened DBAL transactions
  are rejected. Cleanup failure disables further messaging on that runtime.
- Queries do not automatically flush and cannot dispatch commands or events. Nested
  queries retain the parent's unit of work; independent queries discard managed state.
  Query purity against arbitrary SQL is not a PostgreSQL read-only sandbox. Connectivity
  loss during COMMIT can leave an unknown outcome; commands are not automatically retried.
- Dev/test JSON routes and CLI adapters share create/read handlers. HTTP input is
  bounded to 4 KiB and fixed fields; titles allow at most 200 codepoints. Validation
  exposes field/message diagnostics, unexpected failures use generic responses and
  safe operation metadata. Production route absence is tested.

### Native Application events (5b)

The Application-only `Platform/Messaging/EventBus` exposes
**`dispatch(ApplicationEvent $event): void`** on ordinary `application.event.bus`.
One `events` transport uses **`EVENT_TRANSPORT_DSN=sync://`** by default, or
**`doctrine://default`** for async delivery. Producers and listeners use identical
code in both modes. See [README examples](../README.md#publish-and-handle-application-events)
for complete producer/listener classes and [switch commands](../README.md#switch-event-delivery-and-run-the-worker).

#### Data and listener contract

`Platform/Event/BaseEvent` and its direct children `DomainEvent`, `ApplicationEvent`
and `InfrastructureEvent` are empty abstract readonly categories: no state, behavior,
event IDs or metadata. Concrete events are final readonly and directly extend their
exact category. Only inventoried public Application events enter the event bus.

Domain objects may opt into pure, non-service `RecordsDomainEvents` and
`RecordsDomainEventsTrait` under `Platform/Event/Recording`. Protected
`recordDomainEvent()` collects internal Domain facts; public `releaseEvents()` returns
and clears them. Application explicitly selects facts to translate. `CreateTaskHandler`
schedules the Task through its Domain repository, releases its facts, maps only
Domain TaskCreatedEvent to public `Application/CreateTask/TaskCreatedEvent(Uuid $taskId)`
and dispatches it. Collection is explicit and transient; identity remains module-local,
with no universal entity base or optimistic version supplied by this capability.

Private `Infrastructure/EventListener/*Listener` services declare one class-level
`#[AsMessageHandler(bus: 'application.event.bus')]`, optionally with integer priority,
and public non-static `__invoke(ExactApplicationEvent): void`. Zero or multiple
distinct listeners are valid. Listeners use only public data, approved immutable
values, that attribute and exact CommandBus/QueryBus helpers. Vendor adapters belong
under `Infrastructure/Framework/<Library>/EventListener` and retain vendor events.

#### Transactions and delivery

Dispatch requires healthy owned command-handler execution with no enclosing query.
ORM lifecycle callback dispatch is forbidden. Detection uses the handler phase and
stack frames for Doctrine ListenersInvoker/EventManager dispatch; it covers those
paths, not arbitrary callbacks or a fixed-cost stack scan.

- **Sync:** native handling is immediate, inside the producer transaction **before
  final flush**. Listener commands join that root. Pending ORM writes need not be
  SQL-visible to listener queries. A dispatch/listener failure invalidates the root
  even when caught, so it cannot commit. Listener execution adds producer latency and
  holds its transaction/locks open. Use explicit nested commands for required invariants
  that must hold regardless of event transport mode.
- **Async:** native Doctrine sending inserts **one event row** on the exact default
  DBAL connection and producer transaction, before final ORM flush/commit. Business
  writes and enqueue commit or roll back together; other connections cannot consume
  the uncommitted row. Enqueue/flush failure rolls back both. After commit, workers
  invoke the **current registered handlers**. There is no outer worker event
  transaction; listener commands own their usual roots and may commit independently.
  Earlier successful commands can remain committed after later failures.
- Native Messenger preserves successful handlers' **`HandledStamp`s** on a partially
  failed envelope and can skip those handlers on retry. A failed listener may already
  have committed some commands; a crash before ACK can repeat completed work.
  **At-least-once delivery requires module-owned idempotent commands**, business keys
  and transactional uniqueness for each independently committed step, verified with
  duplicates/concurrency. Static checks do not establish business idempotency.
  No global ordering or exactly-once external-effect guarantee applies.

Native serialization, handler work, cascades and queue retention determine resource
usage. External effects cannot be rolled back by a DB transaction.
Invocation state assumes sequential execution, not concurrent fibers sharing a container.

#### Schema and compatibility

Native queue names **`events`** and **`events_failed`** share exactly
**`public.platform_messaging_message`**. The retained initial migration is
`src/Platform/Messaging/Resources/migrations/Version20260912010000.php`, in exact
namespace `App\Platform\Messaging\Resources\migrations`. Setup applies migrations
before workers start; transport `auto_setup` and notification-based receiving/LISTEN
are disabled. Native Doctrine Messenger sending still emits PostgreSQL `pg_notify`.

Actual-schema checks require permanent logged storage, the exact seven columns
(including generated-by-default bigint identity), primary key, queue/availability/
lease/ID index, nullability/defaults and usable index definitions. Module mappings
cannot claim the table; foreign keys involving it fail mapped-owner rules. This is
a narrow table/path exception, not general Platform schema or DI access.

Preflight found the old queues empty. Existing legacy rows are not converted or
deleted, and native workers ignore their old queue names and opaque contents.
Checkouts with legacy rows must drain them using compatible old code before upgrading.
For native events, drain pending, in-flight **and failed** messages before switching
mode or making incompatible event/handler changes; otherwise preserve compatibility
explicitly. Restart workers after source changes. Native messages identify PHP classes;
there is no automatic class/property rename or version upcaster.

Symfony Messenger/Doctrine Messenger/Serializer are locked at **8.1.6**, with added
**PropertyAccess 8.1.4**, **PropertyInfo 8.1.6** and **TypeInfo 8.1.5**. The transport
uses the native Symfony JSON serializer, standard `UuidNormalizer`, and
`DateTimeNormalizer` with `Y-m-d\TH:i:s.uP` to retain microseconds. Tests cover UUID,
immutable dates and known concrete nested public events. Structural DTO permission
does not guarantee arbitrary object-union or polymorphic payloads round-trip; such
contracts need explicit serializer design and tests.

#### Worker and failure operations

Export `EVENT_TRANSPORT_DSN=doctrine://default` in the invoking shell, then use
`./bin/dev up` and `./bin/dev worker start|stop|status`. The export reaches Compose
app/CLI, worker and runner environments; tests explicitly choose isolated modes.
Keep the DSN out of strictly validated `var/docker/local.env`. `up` recreates the app
when its environment changes; stop/start reloads worker source. Worker start refuses
sync mode. Raw Symfony consumption silently skips synchronous receivers, so the
supported wrapper is the mode guard. The optional worker is not started by setup/up.

The configured sequential consumer runs:

```sh
php bin/console messenger:consume events --time-limit=3600 --memory-limit=128M --limit=1000 --sleep=1 --no-interaction
```

Service resets occur between messages. Limits are **soft checks between messages**;
Compose restarts exited workers. Poisoned invocation/ORM state stops further work.
The **300-second redelivery lease has no keepalive or hard per-handler deadline**,
so idempotency must tolerate overlapping long-job redelivery. Worker credentials,
resources and development/test caches retain the existing isolation boundaries.

Ordinary failures receive three retries after the initial attempt, delayed **1/2/4
seconds**, without jitter. Symfony's recoverable/unrecoverable exception classification
is retained. Exhausted/unrecoverable messages persist in `events_failed` until native
`messenger:failed:show`, `retry` or `remove` operations; see [README commands](../README.md#failed-events).
**Operator retry may run handlers inline** in the console process. The command
subclasses change output presentation only; execution/ACK and retry semantics remain
native.

Diagnostics adapters emit fixed metadata without payloads or arbitrary exception
messages/objects. Worker error-detail stamps are redacted while the actual throwable
used for retry classification and other stamps, including HandledStamp, remain native.
Logging failure does not change delivery outcomes. **Queue writers/database access
are trusted**: native deserialization is not a custom allowlist sandbox. Stored
payloads and backups require restricted access; malformed failure representations
may retain original wire data. Output redaction does not sanitize stored payloads.

#### Checks and verification status

The simplified `CqrsPass` checks public event inventory, ordinary listener
declarations/signatures/privacy, EventBus/context references, policy before native
sending/handling, no outer event command transaction, routing to `events`, allowed
DSNs/options and canonical default Doctrine connection/manager wiring. General
source/DI/schema boundaries still apply. It does **not** exhaustively prove vendor
transport/serializer/retry/diagnostics graphs or arbitrary factory/callback bodies.

`tests/Fixtures/NativeEvents` supplies disposable subscriber infrastructure for both
modes. Implementation, actual container/PostgreSQL verification and fresh independent
reviews are complete, with user acceptance recorded on 2026-09-13. The [task record](tasks/05b-native-event-bus.md)
owns exact acceptance journeys and verification evidence.

Resolved module-service references cannot target our event listeners, including
same-module aliases, closures, locators and inline wrappers. Native framework
handler-descriptor wiring remains valid. This prevents a declared DI bypass of
transport selection; arbitrary dynamic callable construction remains outside the
build-time guard's scope.

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
initial owner access uses explicit transactional command orchestration. Activity
uses Application events with the global native transport choice described above.
Later demo use cases retain their own approval gates.

## Reuse and verification

Clean template exports initialize independent identity, secrets, Compose resources
and cookie/key namespaces. Initialization is non-destructive on reruns. Demo omission
is initially supported before database initialization; removal from an installed
application requires a migration design.

Every subtask has discovery/design approval, security/performance review, actual
E2E evidence, a fresh independent reviewer and approval before continuation.
Security/performance bounds are feature-specific and executable where practical.
