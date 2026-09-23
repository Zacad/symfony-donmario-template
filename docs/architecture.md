# Approved architecture

**Active correction (2026-09-22): the [clean Authorizing/native voters/Task ownership
model](tasks/10-authorizing-rework.md) is approved, implemented and verified.** It is
fresh-template only: compatibility with intermediate authorization schemas/data is not
required. Fresh independent review is complete; user acceptance is pending. Earlier verified reworks
remain historical regression provenance. Task 11 has not started, and no Git delivery is
authorized.

**Latest accepted checkpoint (2026-09-16): Task 9 is IMPLEMENTED, VERIFIED, REVIEWED and
USER ACCEPTED, including the `ActorKind` correction.** Following the full
policy-only enforcement design, “accept and proceed” accepted 8b and approved Task 9
implementation, as recorded by main. Task 9 acceptance followed its additional fresh
design/implementation review. [Task 10](tasks/10-task-tracking.md) is **IMPLEMENTED,
VERIFIED, REVIEWED — SUPERSEDED BASELINE, NOT USER ACCEPTED**.
Setup/check/E2E/consumer passed; both fresh implementation reviewers approved without findings.
Earlier awaiting-8b-acceptance records are superseded. Main owns final evidence in the
[Task 9 record](tasks/09-authorization-enforcement.md); the subsequent explicit Git
request authorizes this accepted 8b/9 delivery. Future commits/pushes need fresh authorization.

## Purpose and baseline

A reusable template for multiple applications, with AI-assisted coding guidance,
repeatable checks and explicit human approval gates. MIT license, DonMario.
Single tenancy by default. Linux-first Docker Compose development/testing.
Symfony 8.1 initialized with Symfony CLI, PostgreSQL, Twig/AssetMapper, HTMX when
useful, and API Platform for application APIs. Exact dependencies live in the locks.

Subtasks 1, 2, 3a, 3b, 4, 5b, 6 (including the registration correction), 7, 8a, 8b and
9 (including the enum correction) are accepted.
The delivery designs in historical
[Subtask 4](tasks/04-synchronous-events.md) and [Subtask 5](tasks/05-durable-events.md)
are **superseded by approved [Subtask 5b](tasks/05b-native-event-bus.md)**.
The native implementation is verified in containers/PostgreSQL and independently
reviewed with all findings resolved and accepted by the user on 2026-09-13.
[Subtask 6](tasks/06-web-authentication.md) includes the approved 2026-09-13 registration
correction and is **VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-13.**
Post-correction setup passed; check passed **610 tests / 3942 assertions** with
Deptrac **1031 allowed / 0 violations / 0 uncovered** (`var/test-runs/run-eTQK6EAr/`);
HTTP/PostgreSQL E2E passed **137 tests / 1991 assertions** (`var/test-runs/run-xaiXVV3r/`).
Fresh consumer `/tmp/opencode/donmario-setup-fej1lSca/` passed with embedded **137 tests /
1990 assertions** at `application/var/test-runs/run-1pN3Ocj1/`. Fresh independent
correction reviewer `ses_f64339484ffeQjNteclNnjAlvj` approved after inspecting code/evidence,
with no concrete findings. Earlier approvals cover the unmodified native web-authentication
scope. [Subtask 7](tasks/07-jwt-authentication.md) was approved with “i accept, proceed”,
including the correction requiring `/api/me` to use QueryBus. It is **IMPLEMENTED,
VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-14**. Verification and reviews
completed on 2026-09-13. Final setup retained RSA3072 keys,
dependencies and current migration with app/database healthy. Check passed **695
tests / 4385 assertions**, Deptrac **1246 allowed / 0 violations / 0 uncovered**
(`var/test-runs/run-VU1J5W0M/`); E2E passed **201 tests / 4606 assertions**, all 38
phases (`var/test-runs/run-Dk8kGEH0/`). Consumer `/tmp/opencode/donmario-setup-tr7xoQb4/`
passed with embedded **201 tests / 4607 assertions**, all 38 phases, at
`application/var/test-runs/run-KkpCT8uO/`. Fresh authentication/runtime reviewers
approved after inspecting code/evidence, without rerunning suites; task 7 records
their identities and exact conclusions. Subtask 6's evidence above remains its
accepted historical checkpoint. **Accepted Subtask 8a — Application DTO collections
is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15** under the approved
[8a/8b design](tasks/08-authorizing.md). Final verification passed on 2026-09-14 and both fresh
independent implementation reviewers approved with no findings; neither ran suites.
Reviews completed on 2026-09-14; earlier fixture YAML/static issues are resolved.
**8b Authorizing model/management is IMPLEMENTED, VERIFIED, REVIEWED and USER
ACCEPTED (2026-09-15).**
The user's earlier exact 2026-09-15 “commit, push and proceed”
accepts 8a, authorizes commit/push of the verified 8a checkpoint only, and approves
beginning 8b. Main pushed 8a as `367fdfe` to `origin/main` that day. The subsequent
2026-09-16 request authorizes this 8b/9 delivery; future commits/pushes require explicit authorization. Final 8b verification
and fresh independent review are complete. Subsequent “accept and proceed” accepted
8b and approved Task 9's full policy-only design; its verified/reviewed implementation
was accepted on 2026-09-16. Setup/check/E2E/consumer passed and the fresh reviewers
approved without findings; the Task 9 record contains exact final evidence.

## Modules and data ownership

Installed business modules are **Authenticating**, **Authorizing** and
**TaskTracking**. Authorizing's 8b implementation is user accepted. Names end in `-ing` and
describe the responsibility.

```text
src/Module/<Module>/
  Application/<UseCase>/
    <Name>Command.php or <Name>Query.php
    <Name>Handler.php
    <Name>Result.php                  # when a result DTO is useful
    <Name>Input.php                   # nested CQRS data, not dispatchable
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

Cross-module code depends only on public command/query/result/input/event data. Commands,
queries, results, nested inputs and public events live in Application use-case folders. There is
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
TaskTracking/Application/ListTasks/
  ListTasksQuery.php
  ListTasksHandler.php
  ListTasksResult.php
  TaskListItemResult.php
TaskTracking/Application/CompleteTask/
  CompleteTaskCommand.php
  CompleteTaskHandler.php
  CompleteTaskResult.php
```

Subtask 3a establishes the boundary rules; Subtask 3b implemented the initial Create/Get
handlers, Messenger integration and shared HTTP/CLI adapters. See
[Subtask 3](tasks/03-cqrs-transactions.md) for its historical approval and evidence.
Task 10 expands Task data and adds List/Complete under the same boundaries.

- The supported public Application data path is exactly
  `Application/<UseCase>/<Name>{Command,Query,Result,Input,Event}.php`. UseCase and Name are
  descriptive PascalCase identifiers; Name has a nonempty prefix before its suffix.
  Command/Query/Result/Input/Event suffixes are reserved for data within Application,
  including when checking misplaced classes. Other Application classes remain internal.
- Public data can be imported by another module's Application or adapters. Requests
  go through the appropriate bus; consumers never inject/call another module's handler.
  A public message is an integration API, not an authorization grant.
- Public DTOs follow the data-only rules below. Handlers are private services;
  messages, results, inputs and events are ordinary data objects excluded from discovery.
  Input is nested public data, neither a dispatchable message nor a top-level handler
  result. Collection outputs use named Result envelopes.
- Domain has no dependency on Application DTOs, public events or handlers. It records
  its own `Domain/Event/*Event` facts; Application explicitly translates selected
  facts to public events and maps domain values/entities into public results.
  Public event payloads may reference approved immutable values and concrete public
  event data, never command/query/result/input DTOs or module-internal types.
- A dedicated result DTO is optional. The create use case returns `Uuid`;
  lookup returns `GetTaskResult|null` with UUID/title, nullable owner account UUID and
  nullable completion timestamp. List returns a named envelope of task items and a
  cursor; Complete returns ID, changed flag and first completion timestamp, or null
  for an authorized missing Task. Result data is transport-neutral:
  HTTP and CLI adapters decide status codes, serialization and presentation.

### Repository ports and inward dependencies

Each module defines repository interfaces in its Domain and Doctrine-specific
implementations in `Infrastructure/Persistence`. Symfony aliases bind these ports
to adapters; application handlers inject the Domain interface. The first port is
`TaskTracking\Domain\TaskRepository` (`add`, `find`, `findForCompletion`, `findPage`),
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
  Application implementation, public command/query/result/input data, public events,
  internal Domain/Infrastructure events, our listeners and framework listeners have
  separate, non-overlapping layers. Ordinary module adapters may use their own
  internals and public data; our event listeners have narrower permissions.
  Public command/query/result/input data may reference approved immutable values and
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
  properties, empty constructors and scalar/null literal defaults; CQRS/Input lists
  additionally permit an empty `[]` default. Command/query/result/input
  enums have literal int/string cases. Allowed types are scalars/null (including
  nullable and union forms), declared public data (concrete public events only for
  event payloads), `DateTimeImmutable` and Symfony `Uuid`. Internal Domain/Infrastructure
  events allow only scalar/null and those immutable values. Concrete events directly
  extend their exact category; commands/queries/results/inputs have no inheritance.
  CQRS/Input collections follow the bounded list contract below; event collections
  remain forbidden. Callbacks, interfaces, traits, attributes and additional behavior
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
   forbidden, including named aliases, apart from the exact helper permissions and
   authorization middleware's exact private native decision manager described below.
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
- **Exact API resource data:**
  `Authenticating/UI/Api/AccountIdentityResource` is non-service UI transport data.
  Its API Platform metadata and scalar readonly fields have a narrow exact
  inventory/container classification; this does not exempt arbitrary UI resources
  or Application DTOs. The neighboring provider remains a required private service
  and uses QueryBus. There is no direct-principal query-projection exception.
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
  bus-name stamp, standard input validation, command transaction (commands only),
  authorization, result validation, handling. `ResultValidationMiddleware` validates on stack unwind
  inside invocation scope and before command flush/commit.
  Checks cover these service classes, helper references and shared invocation wiring;
  standard handling's logger setter and leading debug tracing are supported.
  Native Symfony message registrations remain framework-owned; runtime policy admits
  only inventoried application messages. These checks do not exhaustively validate
  the vendor service graph or arbitrary callable bodies.
- Module YAML validation must be explicitly registered. Title/UUID constraints and
  missing-mapping rejection are tested. The 8a structural collection audit checks the
  supported native metadata forms below; other business-constraint completeness needs review.
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
- Dev/test JSON routes and CLI adapters share Create/Get handlers; CLI additionally
  exposes List/Complete (Task 10). HTTP input is
  bounded to 4 KiB and fixed fields; titles allow at most 200 codepoints. Validation
  exposes field/message diagnostics, unexpected failures use generic responses and
  safe operation metadata. Production route absence is tested.

### Application DTO collections (8a: user accepted 2026-09-15)

The synchronous extension covers exact public Command/Query/Result/Input data only.
Events retain their collection-free payload rules and existing wire behavior.
`ContractTypes`, source/Deptrac, module service exclusions and `CqrsPass` classify
the reserved `Input` suffix together; Input is never dispatchable or a top-level
handler result. Collection-bearing Command/Query handler return types are rejected
at compilation, including collections reached through transitive DTO fields and
union members; use a Result envelope. Principal/API-resource exceptions retain their exact scope.

#### Source and loaded metadata contract

- Native nonnullable `array` properties require constructor `@param list<T> $name`.
  Optional promoted `@var` must agree. Items are homogeneous non-null `string`, `int`,
  `float`, `bool`, `Uuid`, `DateTimeImmutable`, concrete public CQRS/Input DTOs or
  backed public data enums. Empty `[]` is the only added default.
- Maps, untyped/mixed arrays, nullable items, item unions, nested generic lists,
  aliases/templates and recursive collection-bearing DTO graphs fail source checks.
  Named DTO nesting with independently bounded collections is allowed. Graph checks
  include ordinary DTO fields and intermediate wrappers, not only list-item edges.
- `tools/Architecture/CollectionDocTypes` parses PHPDoc ASTs and resolves lexical
  namespaces/import aliases; `CollectionContracts` inventories lists, validates
  doc-only public-data dependencies and derives cascade/cycle requirements without
  executing application source. **phpstan/phpdoc-parser 2.3.5** is an explicit direct
  development dependency; no package versions were updated. Runtime needs no PHPDoc parser.
- `CollectionValidationMetadata` checks loaded native Default-group property metadata
  via `CollectionValidationKernel`. `./bin/dev check` invokes
  `php tools/collection-validation.php`, which first runs source policy, then boots
  the audit kernel and compares descriptors with the registered native validator.
  This is a structural metadata audit, not a runtime graph parser.
- Supported forms are exact native sibling property `Type(list)`, `Count` with a
  finite nonnegative integer `max`, and `All` with explicit native `NotNull` and
  `Type` matching the resolved item type, all effective in Default. DTO list items
  require sibling property `Valid`; every ordinary DTO edge leading to a collection
  also requires property cascading. Mappings must be explicitly registered in
  `framework.validation.mapping.paths`; item DTO fields retain their own constraints.
  Arbitrary equivalent wrappers, custom subclasses, class cascades and group-sequence
  overrides do not substitute for these supported native forms. See the
  [README example](../README.md#application-dto-collections-8a-user-accepted-2026-09-15).

#### Two-phase runtime validation

Native Messenger input validation runs before command transaction work. The new
`Platform/Messaging/ResultValidationMiddleware` sits immediately before handling,
then validates returned public DTO objects on unwind, before the owned transaction
can flush/commit. Invalid output or a validator exception becomes the fixed internal
`LogicException` message `cqrs.result_validation: Handler returned invalid data.`;
it is not a client-input validation error and carries no result/violation payload.
Existing invocation failure handling invalidates the root even when a nested command
or query output failure is caught. Scalar/null/value/enum/void results retain their
existing contracts; list outputs require named Result envelopes.

#### Security/performance bounds and completed verification

Readonly arrays are shallow. Validation observes a value graph at one point in time;
it does not universally prevent element references or mutable subclass state.
Trusted in-process code must construct ordinary owned lists of supported data.
Per-use-case limits bound accepted data, not every allocation or traversal: native
`Valid` may traverse even after a count/type violation. External adapters must bound
transport bytes/items before constructing DTOs, and mappings should remain cheap
and database-independent. No universal traversal, deep-immutability or serialization
round-trip proof is claimed. Fixed errors do not erase existing invocation/exception
objects from memory.

Source/metadata positives and negatives, compiled-bus rejection, actual PostgreSQL
rollback after invalid and caught nested outputs, recovery, authentication/event
regressions and fresh-consumer isolation passed in 8a verification. Both fresh
independent implementation reviewers approved with no findings, without running
suites. Final check and consumer verification cover the final compiler guard;
standalone E2E preceded that compiler-only change, with runtime unchanged. Exact
commands, evidence and review identities are in the [task record](tasks/08-authorizing.md)
and [handoff](handoff.md#completed-8a-verification-and-review--2026-09-14).
Verification and reviews completed on **2026-09-14**; **8a was USER ACCEPTED on
2026-09-15**; Task 9 is now the latest accepted checkpoint (2026-09-16).

### Native Application events (5b)

The Application-only `Platform/Messaging/EventBus` exposes
**`dispatch(ApplicationEvent $event): void`** on ordinary `application.event.bus`.
One `events` transport uses **`EVENT_TRANSPORT_DSN=sync://`** by default, or
**`doctrine://default`** for async delivery. Producers and listeners use identical
code in both modes. See [README examples](../README.md#publish-and-handle-application-events)
for the installed producer reference and illustrative listener class, and
[switch commands](../README.md#switch-event-delivery-and-run-the-worker).

#### Data and listener contract

`Platform/Event/BaseEvent` and its direct children `DomainEvent`, `ApplicationEvent`
and `InfrastructureEvent` are empty abstract readonly categories: no state, behavior,
event IDs or metadata. Concrete events are final readonly and directly extend their
exact category. Only inventoried public Application events enter the event bus.

Domain objects may opt into pure, non-service `RecordsDomainEvents` and
`RecordsDomainEventsTrait` under `Platform/Event/Recording`. Protected
`recordDomainEvent()` collects internal Domain facts; public `releaseEvents()` returns
and clears them. Application explicitly selects facts to translate. `CreateTaskHandler`
schedules the Task through its Domain repository, then releases its facts and maps only
Domain TaskCreatedEvent to public `Application/CreateTask/TaskCreatedEvent(Uuid $taskId)`
and dispatches it. Creation performs no authorization grant. Collection is explicit and
transient; identity remains module-local, with no universal entity base or optimistic
version supplied by this capability.

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

### Web authentication (Subtask 6)

This section records the approved Subtask 6 design, including the user's 2026-09-13
correction, and implemented behavior. Correction verification and fresh independent
review are complete; Subtask 6 including the correction is **USER ACCEPTED on
2026-09-13**. Exact evidence and review status live in the
[task record](tasks/06-web-authentication.md).

- **Ownership and writes:** Authenticating owns Account (UUID, canonical email,
  password hash), Domain repository ports and `public.authenticating_account`, with
  module migration `Version20260913010000`. CLI provisioning dispatches raw email and
  plaintext password in `Application/RegisterAccount/RegisterAccountCommand(email, password)`
  and returns the UUID. The handler calls `EmailAddress::normalize`, then
  `PasswordPolicy::validate`, hashes through the Domain `PasswordHasher` port, constructs
  the Account and calls repository `add`. `SymfonyPasswordHasher` only delegates to
  the named native hasher; it does not own business validation. The existing command
  transaction wraps registration validation, hashing and persistence. Database
  uniqueness prevents duplicate/case-variant overwrites.
  Native Symfony computes a password-migration replacement before dispatching
  `Application/UpgradePasswordHash/UpgradePasswordHashCommand(accountId, expectedPasswordHash, newPasswordHash)`.
  This command remains hash-only. Conditional module-local SQL (CAS) prevents stale hash overwrites under the ordinary
  owned command transaction. These synchronous writes use CommandBus; repositories
  never flush/commit. Both command types are sensitive data.
- **Native read exception:** Symfony `form_login` owns login and verification;
  `UI/Http/Security/AccountUserProvider` directly reads detached module-local Domain
  credential snapshots for email login and UUID-based session refresh. This explicit
  exception has no `LoginCommand`, public credential query/result, forwarding handler
  or custom authenticator. It grants no general business-adapter repository policy.
  Domain has no Symfony Security dependency.
- **Exact principal exception:**
  `Authenticating/Infrastructure/Framework/Symfony/Security/AccountPrincipal` is internal
  runtime data, with no entity or service references. Inventory/DI excludes this exact
  class from services and permits Symfony's excluded/deferred `#[CurrentUser]`
  diagnostic placeholder, not an instantiable principal service. It is not a general
  exemption for `UserInterface` implementations or Infrastructure data. Module service
  injection, aliases and inline definitions still cannot register the principal.
  Serialization replaces the reusable password hash with Symfony's documented crc32c
  fingerprint. Changed hashes and deleted accounts invalidate older sessions on refresh.
- **Input:** email is validated ASCII, trimmed of outer ASCII whitespace, lowercased
  and at most 254 bytes, with no provider-specific rewriting. Passwords are valid
  UTF-8, at least 15 Unicode characters and at most 4096 bytes; spaces are preserved,
  NUL and line breaks rejected. A named native `auto` hasher serves provisioning and
  verification. CLI hidden password/confirmation fails without hidden-input support;
  explicit `--password-stdin --no-interaction` bounds input and removes only one
  optional final LF/CRLF as transport framing. CLI responsibilities are secure input,
  confirmation, framing, raw email/password dispatch and fixed-error presentation;
  it performs no business validation and injects no hasher. Passwords never belong in argv, environment, URL queries or
  diagnostics; command/request/passport/exception payloads must not be logged.
- **HTTP/session behavior:** GET `/login` renders the form; native CSRF-protected POST
  `/login` targets `/account`, which requires full authentication. Failure and native
  POST/CSRF logout target `/login`; caller-controlled redirects are ignored. GET/HEAD
  cannot log out. Invalid login CSRF preserves an existing authenticated session.
  Password migration happens after token setup: conflict/operational failure explicitly
  clears the token and invalidates the session. The principal receives its new hash
  only after successful persistence. Login rotates the session; logout invalidates it.
- **Lifetime/storage:** native files live in `var/sessions/<env>` outside cache.
  Cookies are `dm_<PROJECT_ID>_<env>` (Compose passes PROJECT_ID as APP_INSTANCE_ID),
  host-only, path `/`, HttpOnly, SameSite=Lax and Secure=auto. Browser-session cookies
  and GC (`gc_maxlifetime=86400`, probability 1/100) do not impose a hard idle/absolute
  TTL. Separate project identities isolate cookies even on different same-host ports.
- **Throttling:** native `DefaultLoginRateLimiter` uses 5 failures/minute per normalized
  identifier/IP and 25/IP/5 minutes, dedicated filesystem cache and flock storage under
  `var/security/<env>`, fixed namespaces and stable APP_SECRET keying. Cache rebuilds
  retain state. Native empty flock files can be 0666 beneath owner-only 0700 directories;
  session/limiter data remains private. Lock files accumulate and must not be unlinked
  while authentication processes are active.

#### Security/performance bounds

Caddy rejects authentication POST Content-Length above **16 KiB with 413**, and
unframed/streamed requests with **411** before PHP parsing. External `/index.php`
and `/index.php/*` aliases return **404** before internal front-controller rewriting,
closing the ingress bypass. Bounded scalar validation runs before the firewall.
Fixed errors and no-store responses avoid credential payload diagnostics; generic
unknown/wrong-password errors do not promise constant-time account concealment.

Registration email/password-policy validation and native hashing run **inside the
existing command transaction**, increasing transaction duration by the hashing cost.
Native login verification and replacement-hash computation remain Symfony-owned,
before the separate hash-only upgrade/CAS command transaction. Indexed credential reads
return detached snapshots. Native session locks serialize requests sharing a session. Limiter locks
protect counter operations, not the whole in-flight login: concurrent attempts can
exceed sequential thresholds. Native filesystem I/O is **not guaranteed fail-closed**.
This is a single-host baseline, not distributed limiting or edge DoS protection;
there is no arbitrary forwarded-IP trust. Home/liveness remain database-independent,
including with auth cookies. Actual storage/log canaries and per-generation log
collection verify exercised paths; they are not universal secrecy guarantees. Each
generation is stopped first, then its raw logs, including shutdown output, are
collected/checked/redacted before recreation or removal.

Registration plaintext is not persisted, queued or logged, but its public readonly
command and Messenger envelope contain it in memory; validation-exception objects
can retain that command/envelope too. Unsetting a CLI local does not guarantee
erasure of those references or string storage. No public credential query/result/event
is introduced. Fixed output and sensitive-parameter annotations do not make object
dumps safe.

The web firewall excludes `/api`; no session-authenticated API is introduced.
Self-registration, reset/disable administration, remember-me, MFA and OIDC are
outside Subtask 6. Task 9 policy-only business authorization is verified/reviewed,
user accepted on 2026-09-16.

### JWT authentication (Subtask 7)

The approved implementation includes the user's identity-query correction. It is
**IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-14**; verification
and fresh independent reviews completed on 2026-09-13.
[Task 7](tasks/07-jwt-authentication.md) owns the acceptance criteria, completed
verification evidence and two fresh independent review approvals.

- **Native login ordering:** POST `/api/login` uses native Symfony `json_login`,
  the existing email credential provider, password hasher and hash-only CAS upgrade
  command. It has no success handler: only after native authentication and all late
  password-migration listeners finish does the fully authenticated thin
  `UI/Api/LoginController` issue through Lexik. Database, signing or migration failure
  produces no JWT. There is no LoginCommand or custom password authenticator.
- **Stateless isolation:** exact login and bearer firewalls precede the web firewall.
  API requests neither create/invalidate sessions nor accept web-cookie identity.
  The exact `/api/docs.json` firewall has `security: false`, so even an invalid bearer
  header does not require authentication there; the public JSON OpenAPI contract
  describes login and `/api/me` with bearer security. API Platform's browser
  documentation UIs, entrypoint and Doctrine resource integration are disabled.
- **Live identity:** after signature/claim validation,
  `UI/Http/Security/BearerAccountUserProvider` performs a UUID credential lookup via
  the owning Domain repository, extending the explicit native authentication-read
  exception. Domain/Application remain Security-independent. For `/api/me`,
  `UI/Api/AccountIdentityProvider` takes only the trusted principal UUID and calls
  `QueryBus::ask(new GetAccountIdentityQuery($id))`. The co-located
  `Application/GetAccountIdentity/GetAccountIdentityHandler` calls Domain
  `AccountRepository::findIdentityById(Uuid): ?AccountIdentity` and maps the safe
  Domain snapshot to `GetAccountIdentityResult(Uuid $id, string $email)|null`.
  The provider maps that result to `AccountIdentityResource(string $id, string $email)`.
  No credentials cross the public query/result contract; the response is not a
  projection of principal email. This approved **second indexed read** returns current
  identity. Missing account during authentication is 401; deletion between the first
  read and query is 404, with unit coverage for that deletion race. Actual
  HTTP/PostgreSQL instrumentation verifies exactly two account reads and an email
  change visible through the second query rather than principal projection. No
  account identity cache is introduced.
- **Cryptography and claims:** installed Lexik **3.2.0** / Lcobucci **5.6.0** perform
  native cryptography; API Platform Symfony is **4.3.19**. RSA 3072 / RS256, 900-second
  TTL and zero clock skew are fixed. Issued tokens have `typ: JWT` and exactly six
  payload claims: UUID `sub`, `iss`, `aud`, `iat`, `nbf`, `exp`. Issuer is
  `urn:donmario:<APP_INSTANCE_ID>:<env>`, audience adds `:api`; APP_INSTANCE_ID comes
  from PROJECT_ID in Compose. Validate normalized UUID, exact issuer/audience,
  nonfuture nonnegative iat, `nbf = iat`, unexpired exp and **`exp - iat = 900`**
  before account lookup. Native normalized NumericDates are accepted, not an original
  JSON-wire integer guarantee. No email, password-derived value or business permission
  is placed in the token.
  Malformed NumericDate null/array/boolean values can raise Lcobucci 5.6 `TypeError`
  beyond Lexik 3.2's `Exception`-only catch. Narrow `Parser::convertDate` origin
  handling maps these to fixed 401 without a raw JWT parser; real HTTP negatives
  verify this classification rather than an operational 503.
- **Transport:** Authorization Bearer header only, bounded before parsing to an
  8 KiB token plus the 7-byte prefix. No cookie/query/body extraction, token cookies
  or blocklist. Caddy extends the framed 16 KiB authentication POST guard to JSON
  login and preserves front-controller alias rejection. Login requires JSON with
  exactly string email/password fields and no query string; bounded validation and
  normalization precede the native limiter. Web/API share the existing limiter
  service/storage and stable APP_SECRET keying. API errors are fixed and responses
  use no-store, including late failures without session cleanup.
- **Lifetime/browser contract:** no refresh token or disabled-account state. Re-login
  is required after expiry. Deletion denies subsequent authentication, but password
  changes/rehashes and web logout do not revoke existing JWTs. Client logout discards
  its copy; replay remains possible until expiry or signing-key trust removal.
  Same-origin clients hold tokens in memory, never persistent browser storage; reload
  requires login. HTTPS is required outside loopback development. Payloads are readable,
  and memory-only storage does not protect against active XSS.

#### Key lifecycle and operational boundary

The project-specific `jwt_keys` volume holds owner-only unencrypted PKCS8 keys
(0700 directories / 0600 files), mounted read-only to app/console. Test app and runner
read only that run's isolated test keys; worker has no key mount. Independent host
`var/docker/jwt-initialized` metadata matches volume `.identity`, detecting initialized
key loss. Setup generates once, validates/retains on reruns and refuses partial,
mismatched or corrupt state; `up` validates before startup. No build/cache/request/
worker key generation occurs. APP_SECRET, project identity, keys and environment
namespaces remain independently initialized for development, tests and consumers.

The network-disabled native OpenSSL helper exposes `initialize`, `validate`, `rotate`,
`rotate-emergency` and `retire` through `./bin/dev jwt-keys`. Generation files and
manifest are persisted before atomic publication through a `current` symlink; the
helper prunes obsolete generations. Lexik's configuration keeps a literal empty
`additional_public_keys` array, while the **native RawKeyLoader service argument**
resolves the additional array lazily from `/app/var/jwt/verification.json` using
Symfony env processors. This permits keyless compilation and worker startup without
rewriting configuration during rotation.

Switch operations require stopped key users. Planned rotation retains at most one
old **public** key; another overlap rotation requires retirement first. The operator
must remove previous-key trust **within 900 seconds of stopping old-key issuance**,
including downtime; there is no automatic retirement. Emergency rotation retains no
old trust after restart; actual HTTP verification rejects a still-unexpired prior
token and confirms new issuance. Interrupted first initialization can complete the external
marker only when a valid generation and `.identity` already exist; otherwise it
fails preserving state for restore or an explicitly disposable first-init reset.
UID/GID changes require independent key-volume/metadata ownership repair, never
treating valuable signing keys as disposable cache. Exact stop/switch/start and
recovery procedures are in [README](../README.md#jwt-key-operations).

#### Security/performance review

Login adds one signature to native verification and possible rehash/CAS work. Each
valid bearer request costs signature validation and an indexed UUID read; `/api/me`
deliberately adds its QueryBus identity read. At most two keys are tried, with no
password hashing or session locks on bearer requests. Existing limiter filesystem
I/O/concurrency limits still apply; this is not distributed DoS protection. Actual
negative/outage/recovery verification passed. Three-sample local HTTP observations
gave median issuance **527.04 ms** and identity **21.27 ms**; consumer observations
were **525.65 ms / 21.56 ms**. These are local measurements, not an SLA. Task 7
records the evidence; token/key/password canaries establish exercised secrecy paths only.

### Authorization and runtime roles (current rework)

The user approved the clean model on 2026-09-22. Implementation and full verification are
complete and freshly independently reviewed; user acceptance is pending. Historical 8b, Task 9, pre-redesign Task 10 and
the later verified intermediate reworks remain provenance but do not describe or verify
this architecture. The clean model is fresh-template only and has no persisted-data
compatibility requirement for those intermediate designs.

For an implementation-oriented introduction to this section, including request-flow
diagrams and extension examples, see the [authorization engineer guide](authorization.md).

#### Handler capabilities and compiler validation

Every command/query handler declares exactly one `#[Authorize]`. Restricted forms name a
concrete final same-module voter. Permission-bearing forms additionally reference a
module-owned string-backed `*Permission` or `*PermissionEnum` case and explicit stable label; contextual
forms omit permission and label. Actor-unrestricted handlers instead declare exactly
`#[Authorize(public: true)]`. Bare metadata and combinations of public with voter,
permission or label metadata fail compilation. `GetTask` and `ListTasks` share
`task_tracking.task.view` and `View tasks`.

`CqrsPass` aggregates each installed global capability as module, key, label, read/write
access and operations. It injects the compiled descriptors into
`AuthorizationCatalogService`, which owns installed-key validation and bounded capability
listing, and rejects foreign enum ownership, unstable labels, inconsistent capability
metadata or wrong voter mappings. Public actions are code-owned admission decisions and
contribute no permission, capability or assignable role member. There is no authorization
YAML, resource enum/partition or separate catalogue compiler pass.

#### Assignment ownership and permission semantics

Authorizing has no Authenticating or TaskTracking imports, account lookup, business
caller names or business-owner SQL. Subject UUIDs are opaque. Generic raw evaluation can
therefore allow an orphan subject; business voters compose account/Task eligibility
where required. Unknown syntactically valid permissions deny.

The rewritten `Version20260915010000` owns the complete Authorizing schema:

| Domain entity | Table | Natural identity |
| --- | --- | --- |
| `RoleEntity` | `authorizing_role` | `role_key` |
| `RolePermissionMembershipEntity` | `authorizing_role_permission` | `(role_key, permission_key)` |
| `RoleAssignmentEntity` | `authorizing_role_assignment` | `(subject_id, role_key)` |
| `PermissionGrantEntity` | `authorizing_permission_grant` | `(subject_id, permission_key)` |

These are the only Authorizing tables. `Version20260917010000` and
`Version20260920020000` are deleted. There are no resource assignments/grants,
initial-binding table, scope/resource columns, `account_id` columns or synthetic assignment
IDs. Existing intermediate databases must be recreated; no compatibility migration or
fallback read is supported. Opaque `subject_id` references introduce no cross-module FK,
ORM association, join or direct SQL access.

Effective coarse permissions are the additive union of active globally assigned roles
and global direct grants, with deny by default. No wildcard, hierarchy, authorization
cache or JWT/session permission authority exists. Removing the last source denies later
checks after commit; already-authorized work may finish. Business voters apply context
after this coarse check, so an all-permissions role is not a bypass.

#### Generic public APIs and bounds

- `ChangeSubjectAssignmentsCommand` accepts 1-100 distinct natural-key changes for one
  opaque subject. It is atomic/idempotent and reports requested/added/removed/unchanged.
  Each public input has exactly `operation`, `kind`, `key`. The subject-scoped transaction
  advisory lock remains. There is no account lookup.
- `EvaluateSubjectEntitlementsQuery` accepts 1-100 checks across subjects and returns
  ordered decisions from one Authorizing business read. Unknown permission keys deny;
  operational failures never become partial allow.
- `ListSubjectAssignmentsQuery` returns 1-100/default-50 rows from one keyset query:
  roles then permissions, ordered by key. Rows expose only `kind`/`key`; cursor DTOs add
  only their subject binding. There are no
  totals, offsets or cross-page snapshots.
- Resource-access, resource-grant, initial-binding and compatibility-cleanup APIs do not
  exist.

Assignment APIs admit an `assignments` operator or raw global `authorizing.manage` as
specified by their declaration. Catalogue APIs admit a `catalogue` operator or raw
global `authorizing.catalogue.manage`. Raw evaluation admits support reads or
`assignments`. Direct grants remain supported. There are no HTTP/API management routes.
Batch JSON uses `subjectId` and no scope field; CLI command names and positional order
remain stable.

#### Domain organization and runtime roles

Authorizing Domain is grouped by Assignment, Capability and Role business concepts. Only
this module uses explicit technical suffixes such as `Entity`, `ValueObject`, `Enum` and
`Service`; this is not a general module naming rule. A future module must make its own
naming decision rather than copying Authorizing by default.

Folders follow business concepts and reasons to change, not technical classifications or
database tables. Entities, value objects, enums, services and repository ports stay in the
owning concept folder. Cross-concept rules may stay at the Domain root. Do not create
technical buckets or placeholders such as `Domain/Mapping`, `Domain/Entity` or
`Domain/ValueObject`; a suffix never implies a matching namespace.

Within Authorizing, the suffix communicates the type's role:

- `Entity` is a persisted domain object with identity, whether it owns substantial
  lifecycle behavior or narrowly represents a natural-key relationship;
- `ValueObject` is immutable, validated, identity-free Domain data;
- `Enum` is a closed backed vocabulary used in Domain contracts;
- `Service` is a stateless Domain collaborator whose operation does not belong to one
  entity or value object;
- `Repository` remains the suffix for a Domain persistence port;
- purpose-specific names such as `Validator` and `Exception` remain explicit and may be
  shared from the Domain root when genuinely cross-concept.

These Domain suffixes are separate from the repository-wide reserved Application data
suffixes (`Command`, `Query`, `Result`, `Input`, `Event`) described above. Suffixes classify
responsibility; they do not decide richness. `RoleEntity` is rich and owns creation, exact
create-if-absent matching, definition changes, revision increments and irreversible
retirement. The three relationship entities remain deliberately narrow. Repositories
persist and reconstitute these models without moving lifecycle decisions into SQL adapters.

Assignment uses `AssignmentReferenceValueObject(kind, key)` for repository rows and the
Domain continuation reference, `AssignmentChangeValueObject(operation, reference)` for a
mutation and `AssignmentChangeCountsValueObject(added, removed)` for repository effects.
There is no `ChangeSet`, `StoredAssignment` or Domain cursor. Public DTOs map explicitly
to/from these values.

Role keys are immutable; label/permission updates require expected revision, and
retirement is an irreversible tombstone. Every entitlement query joins active role
definitions and memberships. Assignments to retired roles remain listable/removable but
grant nothing.

A role may explicitly combine any installed permissions across modules. There is no
separate per-role permission maximum; the total remains bounded to 4096 active
role-permission edges. Create-if-absent accepts only an exact active match and never
overwrites. Setup defaults are explicit global snapshots:

| Role | Permissions |
| --- | --- |
| `task_tracking.user` | The three Task permissions |
| `authorizing.administrator` | The two Authorizing permissions |
| `application.administrator` | All five currently installed permissions |

Future capabilities are not automatically appended, including to
`application.administrator`.
See [README customization and commands](../README.md#capabilities-runtime-roles-and-setup-defaults).

### Native command/query admission

- One cohesive private lazy Symfony voter per module lives under
  `Infrastructure/Framework/Symfony/Security`. Voters own all predicates for supported
  routes; unsupported routes abstain and supported routes deny wrong tokens or authority.
- Exact `#[Authorize(public: true)]` metadata is compiler-routed to the private lazy
  Platform `PublicAccessVoter`, which recognizes only the compiled public message set and
  grants only the internal `AuthorizationToken`. Handlers cannot reference it directly.
  It has no dependencies or SQL, and its decision-manager tag is removed when unused.
  Public means every actor at the bus boundary, including anonymous; it does not expose a
  transport route or bypass transport/firewall, validation, transaction or result rules.
- Authorization middleware invokes one dedicated private native
  `AccessDecisionManager` with `UnanimousStrategy(false)`. Its lazy iterable contains
  exactly services tagged `app.authorization.voter`. It neither aliases/replaces the
  firewall manager nor consumes ordinary firewall voters. The manager/voters remain
  untraced in every environment so command/query subjects are not retained.
- `AuthorizationToken` implements the native token contract without credentials,
  original principal, business-role cache or parent payload. It carries immutable
  `Actor` and support-read provenance and is never installed in global token storage.
  Invocation/transaction ownership and event frames stay in infrastructure context.
  `ActorKind` remains `Anonymous`,
  `Account`, `Operator` or `Authentication`.
- Voter `supportsAttribute()`/`supportsType()` methods are pure and database-free.
  `CqrsPass` verifies restricted declarations' exact concrete same-module voter and the
  compiler-selected public voter against the private tagged iterator.
- Voters may inject QueryBus and owning Domain read ports/state under narrow source/DI/
  Deptrac rules. They cannot inject handlers, raw buses, ORM/SQL or outward adapters.
  These checks are guardrails, not a sandbox for arbitrary code hidden behind a port.
- `ExecutionContext` derives account actors only from native fully authenticated HTTP.
  Exact adapters establish `accounts`, `assignments`, `catalogue` or `tasks` operator
  scope; the account provider alone establishes account-bound authentication scope.
  CLI/worker execution grants nothing by itself. Actor/scope changes during execution
  fail and invalidate the root.
- Input validation precedes admission. Command admission runs inside the owned root
  transaction; queries gain no transaction. Result validation remains before final
  flush/commit. Caught nested admission/read failures invalidate the root. Same-
  transaction checks do not serialize concurrent revocation.
- Event frames retain the pinned actor/transaction during synchronous delivery; async
  delivery starts anonymous. The token exposes no caller fact or caller-derived authority.
  Frames unwind in `finally` and root reset cannot erase an active event frame. Vote
  diagnostics remain fixed and payload-free.

## TaskTracking use cases and CLI (Task 10)

The Task use cases are part of the approved
[authorization correction](tasks/10-authorizing-rework.md). Clean Authorizing
implementation, verification and fresh review are complete; user acceptance is pending. The original
[Task 10](tasks/10-task-tracking.md) and later intermediate rework evidence remain
historical regression records.

### Owned state, admission and atomic creation

TaskTracking owns `public.task_tracking_task`: UUIDv7 ID, title, nullable immutable
`ownerAccountId`, and nullable `completedAt` (null means open). Migration
`Version20260916010000` adds `owner_account_id UUID` and
`completed_at TIMESTAMP(0) WITHOUT TIME ZONE`, both nullable with null defaults.
Existing rows remain unowned/open. Owner UUIDs are opaque: no cross-module FK, ORM
association, SQL read/write or join. `Version20260920010000` adds
`task_tracking_task_owner_id_idx (owner_account_id, id)`. No ownership transfer or
reopen use case is supplied.

| Public use case | Account-actor admission | `tasks` operator behavior |
| --- | --- | --- |
| `CreateTaskCommand(title, ownerAccountId = null): Uuid` | Persisted self owner plus global `task_tracking.task.create` | May create unowned or choose any persisted account |
| `GetTaskQuery(id): GetTaskResult\|null` | Persisted actor, global view and exact owner; missing/foreign/unowned deny | May read any Task |
| `ListTasksQuery(ownerAccountId = null, limit = 50, after = null): ListTasksResult` | Persisted self owner target plus global view | Null means all tasks; may filter by any owner UUID |
| `CompleteTaskCommand(id): CompleteTaskResult\|null` | Persisted actor, global complete and exact owner; missing/foreign/unowned deny | May complete any Task |

Complete and view are independent coarse permissions. List returns owner-filtered open
and completed tasks. Get/List items contain `id`, `title`, `ownerAccountId`,
`completedAt`; completion returns `id`, `changed`, `completedAt`. TaskTracking's private
native voter owns account existence, global entitlement and ownership composition.

`CreateTaskHandler` constructs/schedules the Task and translates the Domain creation
fact into the existing UUID-only public `TaskCreatedEvent`. It performs no authorization
grant. Task ownership supplies the contextual predicate for subsequent account access;
global permission alone is insufficient.

`EventPolicyMiddleware` establishes an event frame around native delivery. It retains the
pinned actor during synchronous delivery, but the token exposes no caller fact or
caller-derived authority. Sync retains actor/transaction; workers start anonymous with
independent roots/resets. The frame unwinds in `finally` and supplies neither durable
service identity nor queued publisher authority.

### Bounded listing, indexes and snapshot limits

Pages are 1–100/default 50, ordered by ascending Task UUID. Canonical opaque cursors are
at most 512 characters and bind a version, owner target (or null operator-wide target)
and last UUID. A cursor is pagination data, never authority; admission runs on each page
and a cursor cannot be reused with a different owner. There are no totals, offsets,
refill loops or cross-page snapshots. UUIDv7 order is not commit order.

TaskTracking uses `findPage(limit, after, ownerAccountId)` with one lookahead row. The
owner-bound path uses `(owner_account_id, id)`; operator-wide pages use primary-key
order. There is no per-task permission-query loop or authorization cache.

Expected business SQL read budgets excluding authentication are:

| Journey | Maximum reads |
| --- | --- |
| Raw entitlement batch | **1** |
| Account Task Create | **2**: account existence and global entitlement |
| Account Task Get | **3**: account existence, entitlement and Task ownership lookup |
| Account Task List | **3**: account existence, entitlement and owner page |
| Account Task Complete | **4**: account existence, entitlement, ownership lookup and locking lookup |
| Operator Task List | **1**: Task page |

These preserved budgets and N=100 owner-index paths pass clean-model PostgreSQL
verification. Query/row bounds do not guarantee latency or universal plans.

Queries gain no automatic transaction or consistent multi-read snapshot. Permission
revocation, account deletion and Task changes can race with a page. Even a command's
same-transaction admission does not serialize against revocation: already-authorized
work can finish. Completion's row lock protects the Task transition, not permissions.

### Completion locking and persistence precision

`Task::complete(DateTimeImmutable): bool` records the first timestamp only. Repeating
completion returns `changed = false` with the original timestamp. The handler samples
time after `findForCompletion` returns, under the owning row lock for persisted tasks;
pending insertions need no persisted-row lock. Domain normalizes to UTC whole seconds
to match native Doctrine persistence precision. No completion event or
activity integration is introduced here; that remains Task 11.

`DoctrineTaskRepository::findForCompletion` requires the caller's active transaction:

1. A matching scheduled deletion conflicts. A matching pending insertion is returned
   directly, preserving nested create/complete/repeat before the root flush.
2. Existing state is read with a pessimistic write lock using field-only array hydration,
   avoiding replacement of managed fields. A missing unmanaged row returns null; a
   missing row with a managed object conflicts. A previously unmanaged/uninitialized
   object is loaded/initialized under the acquired lock.
3. Compare locked database state with Doctrine's original state across ID, title, owner
   and completion (UUID/date values compared by value). If they match, return the same
   managed object, preserving local dirty changes and earlier nested completion.
4. If database state changed, compare current managed state to the original. A clean
   stale object is refreshed under the lock; a locally dirty stale object raises
   `Task completion conflict.` instead of overwriting pending work.

The lock lasts until the root transaction ends; rollback covers completion and nested
changes. Repositories/handlers never flush, commit or automatically retry. This handles
the specified unit-of-work state paths, not arbitrary concurrent in-memory mutation.

### Adapter outputs and errors

The four CLI adapters use exact operator `tasks` scope. `create [--owner]` prints only
the new UUID; `show` returns expanded task JSON; `list [--owner] [--limit] [--after]`
returns `tasks` and `next`; `complete` returns ID, changed flag and first timestamp.
Owner options are business targets, not actor impersonation. Serialized
timestamps use UTC `Y-m-d\TH:i:s\Z`; nullable owner/completion remain JSON null.
See [README CLI examples](../README.md#task-10--tasktracking-use-cases-and-cli).

- Exit **0**: successful UUID/JSON, including an empty list or repeated completion.
- Exit **2**, `Invalid task input.`: malformed owner option and invalid List/Complete
  inputs/cursors. Create/Show message validation retains `field: message` violations.
- Exit **1**, `Task not found.`: authorized missing Show/Complete target.
- Exit **1**, `Task operation failed.`: operational failures/conflicts, including a
  well-formed but nonexistent selected owner. That creation rolls back; absence of
  `--owner` is instead valid unowned creation. Native syntax errors use Console diagnostics.

Dev/test HTTP still exposes only Create/Get. POST accepts only `title`, derives the
  owner target from native identity, and relies on Task voter self-ownership enforcement.
GET includes owner/completion. Missing, foreign and unowned Tasks deny account requests
before handling; a deletion after admission may still reach the 404 mapping. Existing
fixed errors remain: `invalid_payload` (400),
`body_too_large` (413 above 4 KiB), `unsupported_content_type` (415), `validation_failed`
with violations (422), `Access denied.` (401 anonymous / 403 authenticated),
`task_not_found` (404 after admission), `operation_failed` (500); responses use no-store.
Twig and business API adapters retain their later gates.

## Adapters, cache and demo

API Platform DTO providers call queries; processors call commands. Twig, CLI and
API share use cases. OpenAPI and explicit API contracts provide the SPA seam.

Symfony filesystem cache is the baseline. A later tested Valkey configuration
switches declared application pools. Cache keys, invalidation and post-commit
behavior must preserve authorization and avoid publishing rolled-back data.
Session/throttling storage is configured deliberately rather than silently switched.

TaskTracking implements Create/Get/List/Complete with global coarse permissions and
voter-owned immutable-owner context. The clean Authorizing correction preserves these
semantics and has passed full verification and fresh review; user acceptance is pending.
Completion activity
belongs to Task 11 and will use the global native transport choice described above.
Later demo use cases retain their own approval gates.

## Reuse and verification

Clean template exports initialize independent identity, secrets, Compose resources
and cookie/key namespaces. Initialization is non-destructive on reruns. Demo omission
is initially supported before database initialization; removal from an installed
application requires a migration design.

Every subtask requires discovery/design approval, security/performance review, actual
E2E evidence, a fresh independent reviewer and approval before continuation.
Security/performance bounds are feature-specific and executable where practical.
