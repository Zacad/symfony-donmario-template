# Working on this template

## Start here

**Active approved change:** [Subtask 5b](docs/tasks/05b-native-event-bus.md) implements
a thin EventBus with global native Messenger sync/Doctrine transport switching.
Sync listeners execute inside the producer transaction. Implementation, actual
container/PostgreSQL verification and fresh independent reviews are complete, with
all findings resolved. **User accepted 5b on 2026-09-13.** Next: separate Subtask 6
web-authentication discovery/design in a fresh session; obtain design approval
before implementation. Latest `check`: **496 tests /
3226 assertions**; E2E: **88 tests / 1270 assertions**. See the task record for evidence.

Use subagents and paralelize work when possible and not affect results. Treat using subagents as default way of work for providing faster results.

- On a fresh session, read `docs/handoff.md`; reconcile its dated status with the
  active task record and subsequent user instructions.
- Read `README.md`, `docs/architecture.md`, `docs/roadmap.md`, and the active task record.
- Inspect `composer.json`, `composer.lock`, and `symfony.lock` before assuming a feature is installed.
- Work only in this standalone repository. Inspect user changes before editing.
- Prefer idiomatic Symfony, KISS and YAGNI; make dependencies and module boundaries explicit.
- Use the Symfony documentation matching the installed **8.1** release.

## Mandatory task workflow

1. Discover the current code and requirements for one coherent subtask.
2. Present a design with acceptance criteria and explicit security/performance review.
3. Obtain user approval **before implementing** that subtask.
4. Implement and prove the agreed journey end to end using actual containers and PostgreSQL.
5. Request a **fresh independent subagent review** with only the task's relevant brief,
   changed files and evidence. Resolve findings and reverify affected behavior.
6. Report results and obtain user approval before starting the next subtask.

A blocked or unrun check means the task is incomplete. General design approval is
not permission to implement the whole roadmap. Git commits/pushes require an
explicit request; Symfony CLI scaffolding must use `--no-git`.

## Commands

Use `./bin/dev` for setup, Symfony console, Composer, checks and tests. Host PHP,
Composer and Symfony CLI are not prerequisites. All task evidence should include
the exact command, result and meaningful observable behavior.

- `./bin/dev check`: validation, audit, static analysis, lint and Symfony style.
- `./bin/dev test`: real HTTP/database E2E, including outage and recovery phases.
- `./bin/dev verify-setup`: fresh consumer checkout and development/test isolation.

Run appropriate checks once changes are ready; repeat for new changes or unresolved
failures. Tests must establish behavior, not merely repeat implementation details.
Never redirect destructive tests to development data or expose local secrets in
tool output, test artifacts, source control or image contexts.

## Code conventions

- Install applicable Symfony components through Composer/Flex and review recipes.
- Use attributes, autowiring, autoconfiguration, typed properties and constructor promotion.
- Keep controllers/adapters thin. Use Symfony primitives for security, validation,
  caching and messaging rather than custom frameworks.
- Use PHP CS Fixer's `@Symfony` rules and PHPStan at the configured level.
- Namespace business modules as `App\Module\<ResponsibilityEndingInIng>`.
- Follow the module contract/data-ownership rules in `docs/architecture.md`.
- Co-locate commands, queries, handlers, useful results and public events in `Application/<UseCase>`.
  Public data uses descriptive `*Command`, `*Query`, `*Result`, `*Event` names at that exact
  depth; handlers/helpers remain module-internal. DTOs are data, not services.
  Other modules use this public data API through buses. Domain cannot depend on
  Application DTOs or public events. Domain records internal `Domain/Event/*Event`;
  Application explicitly translates selected facts to public events. No current
  `Contract` path. Public events cannot carry command/query/result DTOs or internals.
  Concrete events directly extend their exact empty abstract readonly category in
  `Platform/Event`: `BaseEvent -> DomainEvent, ApplicationEvent, InfrastructureEvent`.
  No primitive state, behavior, event IDs or metadata; no broad Domain-to-Platform
  permission. Exclude all event data/primitives from services. Keep source, Deptrac
  and container classification aligned.
- Domain objects may opt into the exact `Platform/Event/Recording/RecordsDomainEvents`
  interface and `RecordsDomainEventsTrait`. These pure, non-service support types
  provide protected recording and public release of internal Domain events. Application
  explicitly selects facts to translate after calling the entity's release method;
  the generic DomainEvent collection must not be interpreted as all creation facts.
  Identity stays local; no universal BaseEntity/BaseAggregateRoot or optimistic
  version is introduced by this capability. Keep this permission Domain-only.
- Our internal `Infrastructure/Event` and private `Infrastructure/EventListener`
  are distinct from vendor adapters in `Infrastructure/Framework/<Library>/EventListener`.
  Our listeners accept exact public Application events and use only public data,
  approved values, the handler declaration and exact CommandBus/QueryBus helpers;
  they cannot inject repositories, handlers, ORM or raw buses, even from their module.
- Define repository interfaces in the owning module's Domain and Doctrine adapters
  in Infrastructure/Persistence. Inject Domain ports into application handlers;
  adapters compose EntityManager and do not flush/commit. Enforce inward dependencies.
- `Platform` contains narrowly scoped technical infrastructure, including the
  current health endpoints; it is not a shared business-model directory.

## Messaging and transactions

- Application/UI use exact CommandBus/QueryBus helpers. Application alone may use
  `EventBus::dispatch(ApplicationEvent): void`. Raw Messenger services, envelopes,
  stamps and invocation state stay internal. Private listeners handle public events
  with ordinary `#[AsMessageHandler(bus: 'application.event.bus')]` registration.
- Dispatch requires healthy owned command-handler execution, never an enclosing
  query or ORM lifecycle callback. Stack-based Doctrine callback detection has
  bounded coverage, not an arbitrary callback sandbox.
- `EVENT_TRANSPORT_DSN` globally selects `sync://` (default) or `doctrine://default`.
  Sync listeners execute immediately inside the producer transaction **before final
  flush**; their commands join its root. Pending writes need not be SQL-visible.
  Event dispatch failure invalidates the root even if caught.
- Async dispatch inserts **one native event row** on the same default DBAL connection
  and producer transaction. Workers use current handlers, with no outer event
  transaction; each listener command owns its usual root. Earlier committed commands
  can survive later listener failure. Native HandledStamps retain partial handler
  success on retry; crashes/partial listeners still require module-owned idempotency
  and transactional uniqueness for every independently committed step. No global
  ordering or exactly-once external effects are promised.
- Required invariants use explicit nested commands. Retain ORM `wrapInTransaction`
  and context/rollback-only invalidation for caught failures. Handlers/repositories
  never flush/commit. Independent operations reset ORM/context; cleanup failure
  disables further runtime messaging.
- Native Symfony JSON serialization uses standard UuidNormalizer and microsecond
  DateTimeNormalizer configuration. UUID, immutable dates and known concrete nested
  events are tested; do not infer arbitrary object-union/polymorphic round-trips.
  Queue/database writers are trusted. Restrict stored payloads/backups, including
  original wire data that native malformed-message failures may retain.
- Diagnostics adapters emit fixed metadata without payload/exception dumps, retaining
  native retry classification and HandledStamps. Failed-message command subclasses
  redact presentation only: native operator retry can execute handlers inline.
  Ordinary retries use 1/2/4-second delays; exhausted/unrecoverable events remain
  in `events_failed` for explicit operator action.

## Event operations and boundaries

- Export `EVENT_TRANSPORT_DSN` in the invoking shell; Compose passes it to app/CLI,
  worker and runner. Tests select isolated modes explicitly. Do not add it to the
  strict settings/credentials file `var/docker/local.env`. `./bin/dev up` recreates
  the app when its environment changes. Run setup migrations before starting workers.
- Use `./bin/dev worker start|stop|status`; start refuses sync mode. Raw Symfony
  consumption silently skips synchronous receivers, so use the supported wrapper.
  Stop/start workers after source changes. Sequential workers reset between messages
  and recycle after soft 3600-second/128M/1000-message limits. The 300-second lease
  has no keepalive or hard handler deadline; idempotency must tolerate overlaps.
- Queues `events` and `events_failed` use only `public.platform_messaging_message`
  and the retained exact `Platform/Messaging/Resources/migrations` path/namespace.
  Preflight found legacy queues empty. Old rows are neither converted nor deleted;
  new workers ignore old opaque rows. Drain legacy rows with compatible old code.
  Drain native pending/in-flight/failed events before mode or incompatible DTO/handler
  changes, or explicitly preserve compatibility. No automatic upcaster exists.
- Use module-owned migration namespaces/paths, unique UTC timestamps and reviewed
  module-local SQL, plus the exact Platform Messaging exception. Setup applies pending
  migrations without resetting data. No broad Platform schema or DI exemption applies.
- Source/DI/schema checks retain module boundaries. The simplified CqrsPass validates
  application handler/helper wiring, event policy/routing/options and default Doctrine
  ownership; do not claim exhaustive vendor transport/serializer/retry graph validation,
  business idempotency or a runtime sandbox. See `docs/architecture.md` for exact limits.
  `tests/Fixtures/NativeEvents` is disposable verification infrastructure.

Historical [Subtask 4](docs/tasks/04-synchronous-events.md) and
[Subtask 5](docs/tasks/05-durable-events.md) delivery designs are **superseded by
Subtask 5b**. Distinguish implemented checks from pending verification/review; obtain
separate design approval before implementing Subtask 6. Authentication and authorization have separate
approval gates.
