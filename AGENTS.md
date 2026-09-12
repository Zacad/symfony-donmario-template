# Working on this template

## Start here

Use subagents and paralelize work when possible and not affect results. Treat using subagents as default way of work for providing faster results.

- On a fresh session, read `docs/handoff.md` for the latest handoff and approval status.
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

Future sessions must distinguish approved conventions from implemented checks.
Subtask 2 implements source/contract, compiled service, metadata, migration and
public-schema boundaries with the exact coverage/limitations in docs/architecture.md.
Subtask 3a updates public-data placement and service exclusions. Subtask 3b implements
synchronous buses, YAML validation, handler/wiring checks, root-owned transactions,
and dev/test HTTP plus CLI create/read adapters. Application/UI use the exact
CommandBus/QueryBus helpers; Application alone may also use ApplicationEventRecorder.
Raw Messenger services, delivery and invocation state stay internal.
Handlers/repositories do not flush. Nested failures invalidate the outer command,
and independent operations reset ORM/context state. Read the active task record for
verification/review/acceptance status. Revised Subtask 4, including the opt-in recording
refactor, is implemented, fully verified, independently reviewed and accepted by the
user. Next is separate Subtask 5 discovery/design; obtain approval before implementation.
Required effects use explicit nested command orchestration. Best-effort synchronous
events run only after confirmed commit and root cleanup; listener commands have fresh
independent transactions. Retain producer success on listener failure, log safe
metadata and continue. FIFO buffers allow 100 events/root and 100/delivery session;
valid overflow is dropped and diagnosed. No outbox or durable retry is implemented.
Retain ORM wrapInTransaction and context/rollback-only invalidation for caught failures.
Recording requires healthy owned command-handler execution, with no query or ORM
lifecycle callback. Current stack-based Doctrine detection has bounded coverage, not
an arbitrary callback sandbox. Cleanup failure disables further runtime messaging;
safe logging failure cannot replace committed success. Read architecture for limits.
EventObserving is a disposable test-fixture module, not a production business module.
Use module-owned migration namespaces/paths, unique UTC timestamps and reviewed
module-local SQL. Setup applies pending migrations; it never resets data.
Authentication and authorization arrive in their own subtasks.
