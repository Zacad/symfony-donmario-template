# Session handoff — Symfony application template

## Status and next action

Date: **2026-09-12**

Repository: `/var/home/adam/Projects/symfony-donmario-template`

The user has accepted:

- **Subtask 1:** repository and Docker runtime foundation.
- **Subtask 2:** module/persistence boundaries, including the correction introducing
  Domain repository interfaces and Infrastructure Doctrine implementations.
- **Subtask 3a:** Application public-data co-location, dependency boundaries and
  service exclusions; accepted on **2026-09-11**.
- **Subtask 3b:** synchronous CQRS, YAML validation, shared HTTP/CLI adapters,
  transaction coordination and handler/wiring checks; accepted on **2026-09-11**.
- **Subtask 4:** layered events, best-effort postcommit delivery, category-only
  primitives and the opt-in Domain-event recording interface/trait.

Implementation, actual container/PostgreSQL verification and independent reviews
are complete for all accepted subtasks. All review findings are resolved.

**Subtask 4 is accepted, including the opt-in recording refactor.** Implementation,
verification and fresh independent reviews are complete, with all findings resolved.
**Next: separate Subtask 5 discovery/design — durable outbox/optional async delivery.**
No Subtask 5 design or implementation has been approved; obtain design approval
before implementing it.

This is a dated handoff. Reconcile it with subsequent user instructions, current
task records and the working tree when resuming; refresh it at the next handoff.

## Read first

Paths below are relative to the repository root:

1. `AGENTS.md`
2. `README.md`
3. `docs/architecture.md`
4. `docs/roadmap.md`
5. `docs/tasks/04-synchronous-events.md` — accepted implementation, recording follow-up and evidence
6. `docs/tasks/03-cqrs-transactions.md` — accepted 3a/3b historical decisions and evidence
7. `docs/tasks/02-module-persistence.md` — accepted persistence foundation
8. `composer.json`, `composer.lock` and `symfony.lock`

Inspect current Git status and history before editing. The repository branch is
`main`; initial checkpoint commit is `41b368f`, with accepted 3b work and Subtask 4
changes uncommitted. New CQRS/event source/config/test files are still untracked. No remote is
configured, and the user explicitly deferred pushing. Treat modified and untracked
files as existing project work. Further commits/pushes require an explicit request.
Preserve the user's instruction near the top of `AGENTS.md` to use subagents and
parallel work by default when independent.

## Implemented foundation

- Symfony **8.1**, PHP **8.5**, FrankenPHP, Twig/AssetMapper and PostgreSQL **18**.
- Docker-first tooling through `./bin/dev`.
- Liveness/readiness endpoints, a dedicated readiness connection, isolated
  development/test resources and protected locally generated credentials.
- Doctrine ORM **3.7.0**, Migrations **3.9.7**, MigrationsBundle **3.7.0**,
  Symfony UID **8.1.5** and Deptrac **4.7.1**. Exact versions are locked.
- One default business EntityManager/connection.
- Messenger **8.1.6** and Validator **8.1.6** with synchronous command/query buses.
- Setup applies checked-in migrations without resetting data; `up` starts services.
- Module migrations have unique UTC timestamps and pending migrations execute
  chronologically across namespaces, with per-migration transactions.

The last development setup reported the application ready at
`http://127.0.0.1:8080`; check current runtime state if needed after resuming.

## Repository convention — explicit user requirement

TaskTracking currently contains:

```text
src/Module/TaskTracking/
  Application/
    CreateTask/{CreateTaskCommand.php,CreateTaskHandler.php,TaskCreatedEvent.php}
    GetTask/{GetTaskQuery.php,GetTaskHandler.php,GetTaskResult.php}
  Domain/
    Task.php
    TaskRepository.php
    Event/TaskCreatedEvent.php
  Infrastructure/
    Persistence/
      DoctrineTaskRepository.php
  Resources/
    config/services.yaml
    config/validation.yaml
    migrations/Version20260911000100.php
  UI/
    Http/TaskController.php
    Console/{CreateTaskConsoleCommand.php,ShowTaskConsoleCommand.php}
```

Domain owns the repository interface:

- `add(Task): void`
- `find(Uuid): ?Task`

The Doctrine adapter composes EntityManager and implements that interface.
Symfony binds the interface to the private implementation. Application handlers
must inject Domain ports.

**Repositories do not flush or commit.** Transaction coordination belongs to the
application transaction boundary implemented in Subtask 3b.

Task retains approved Doctrine mapping attributes but has no Infrastructure
import or `repositoryClass` reference.

Repository interfaces are module-internal ports. Public command/query/result data
uses `Application/<UseCase>/<Name>{Command,Query,Result,Event}.php`; neighboring handlers
and helpers stay internal. Domain records internal facts and Application explicitly
maps selected facts to public events. Domain has no Application DTO/public-event
dependency; public events cannot carry command/query/result DTOs or internals. No
current `Contract` path remains. Historical 3a/3b event direction is superseded by 4.

## Architectural checks and constraints

Implemented checks cover:

- Source namespaces, paths, public contracts and inward dependencies.
- Module service registration/defaults and resolved DI edges.
- Entity/migration inventories.
- Doctrine metadata ownership and actual PostgreSQL public-schema boundaries.

Subtask 3a separates public Application data and event data from Application
implementation in Deptrac, shares their classification with source/container checks,
and excludes DTOs from required service discovery while requiring handlers.
Early/late DI checks reject explicit/inline data-service registrations. Subtask 3a
is verified, independently reviewed and accepted; evidence is in the Subtask 3 record.
Subtask 4 extends classification to exact category primitives, internal events and
private listeners; full verification and fresh independent reviews are complete,
with all findings resolved and user acceptance recorded, including the recording follow-up.

Domain cannot depend on Application, Infrastructure, UI or migration code.
Application cannot depend directly on concrete Infrastructure/UI implementations.
Domain/Application cannot use runtime Doctrine/PDO APIs.

Mapping exceptions are narrow: exact Doctrine mapping declaration types
(including the singular override declaration values), the normal ORM namespace
import and the Symfony Doctrine UUID mapping type.

Compiled DI permits an Infrastructure implementation through a declared,
same-module Domain interface. It handles direct constructor/property/method
injection, named arguments and inline service layer context. Port types are not
inferred from arrays, generic locators, factory arguments or inline adapters.

Read `docs/architecture.md` for exact guarantees and limitations. These checks
are not a runtime sandbox or universal dynamic-SQL analysis.

Module configuration uses YAML. Keep framework/Platform exceptions explicit and
narrow when designing CQRS integration.

Application/UI use the exact CommandBus/QueryBus helpers. Subtask 4 additionally
permits ApplicationEventRecorder from Application and those bus helpers from our
private Infrastructure event listeners. Raw Messenger services, delivery and
invocation state remain internal. The compiler checks
handler cardinality/co-location/signatures, shared invocation state and middleware
wiring. The logical invocation scope precedes validation; the physical transaction
starts afterward. Nested failures prevent outer commit even if caught. Independent
operations reset ORM/context state; queries never automatically flush and cannot
dispatch commands. See the Subtask 3 record for accepted CQRS evidence and Subtask 4
for the event extension's completed verification/review and user acceptance.

### CQRS/event implementation entry points

Subtask 4 implementation references:

- `src/Platform/Messaging/CommandTransactionMiddleware.php`: the root Doctrine
  retained ORM wrapInTransaction callback, pre-flush result/failure checks,
  rollback-only invalidation and failure cleanup.
- `src/Platform/Messaging/InvocationMiddleware.php` and `InvocationContext.php`:
  shared nesting, handler/lifecycle guards, event buffering, failure tracking,
  root-only ORM/context reset and delivery only after confirmed commit/cleanup.
- `src/Platform/Messaging/ApplicationEventRecorder.php`, `BestEffortEventDispatcher.php`,
  `EventDeliveryContext.php` and `EventPolicyMiddleware.php`: exact public-event
  recording, separate bounded FIFO delivery, safe diagnostics and private bus access.
- `src/Platform/Messaging/EventListenerInvoker.php`: descriptor-owned lazy listener
  resolution/construction inside Messenger's per-handler catch, with safe logical
  listener identity. Compiler/DI checks permit only this exact wrapper/closure/target.
- `src/Platform/Event/`: empty abstract readonly BaseEvent and its three categories.
- `src/Platform/Messaging/CommandBus.php`, `QueryBus.php`,
  `MessagePolicyMiddleware.php` and `DispatchResult.php`: exact DTO dispatch,
  rejection of caller envelopes/stamps and result/exception propagation.
- `config/packages/messenger.yaml` and `config/services/messaging.yaml`: explicit
  middleware order and shared technical services.
- `src/Platform/Architecture/CqrsPass.php`, `ContractTypes.php`,
  `ModuleServicesPass.php`, `tools/Architecture/DeptracRules.php` and `SourceRules.php`:
  supported public data, exact facade permissions, handler ownership/privacy and
  wiring guarantees, including exact event-category/recorder/listener exceptions.
  Command/query buses still reject event DTOs.
- `tests/E2E/CqrsTest.php`, `tests/Architecture/CqrsTest.php` and
  `tests/Fixtures/Cqrs/`: real compiled-kernel and PostgreSQL failure/recovery patterns.
- `tests/E2E/EventsTest.php`, `tests/Architecture/Event*Test.php`,
  `tests/Fixtures/Events/` and `tests/Fixtures/EventCompilation/`: event journeys and
  boundary fixtures. EventObserving is disposable test infrastructure, not a
  production business module.
- `docker/tools/test.sh` and `check.sh`: isolated snapshot execution and test phases.

The shared journeys are `CreateTaskCommand -> Uuid` and
`GetTaskQuery -> GetTaskResult|null`. HTTP uses dev/test-only `POST /_demo/tasks`
and `GET /_demo/tasks/{id}`; CLI uses `app:task:create` and `app:task:show` through
`./bin/dev console`. These are pre-authentication demonstration adapters.

## Subtask 4 final verification and review

Original event implementation runs, all exit **0**, preceding the bounded recording
follow-up below; these were not rerun for documentation alignment:

| Command | Result and observable behavior | Evidence |
| --- | --- | --- |
| `./bin/dev check` | **429 tests, 2825 assertions**; Deptrac **651 allowed / 0 violations / 0 uncovered**; full checks passed | `var/test-runs/run-FBkwdQNX/checks.log` |
| `./bin/dev test` | **67 tests, 1327 assertions**, including **16 event tests / 646 assertions**; actual HTTP/CLI/PostgreSQL event failure/recovery and full E2E passed | `var/test-runs/run-LGHB90if/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Post-fix clean-checkout repeatability and full event HTTP/CLI/PostgreSQL failure/recovery passed, preserving development data | `/tmp/opencode/donmario-setup-g66L7xZX/` |

Fresh independent reviewers both reapproved the latest code and evidence after
findings were resolved:

- Runtime: `ses_f6b6b07d8ffeyOaY0gqScoL2ud` — approved.
- Boundaries: `ses_f6b6b06d7ffeaMjEEM2a0gEQNP` — approved.

Corrections keep listener resolution/construction inside Messenger's per-handler
catch through the exact descriptor-owned EventListenerInvoker/ServiceClosureArgument
wiring, preserving safe logical listener diagnostics and narrow DI permissions.
SourceRules rejects statically named cross-listener calls, including same-layer
edges ignored by Deptrac. Actual PostgreSQL `postFlush` callbacks catch recording
and command rejections, yet rollback-only prevents commit and held services recover.
These results preserve the documented bounded lifecycle-detection coverage.

### Opt-in recording follow-up — latest evidence

The user approved `RecordsDomainEvents` plus `RecordsDomainEventsTrait` under
`Platform/Event/Recording`, Task adoption and explicit selection of Domain facts
for public translation. Identity stays local. No BaseEntity/BaseAggregateRoot,
optimistic version or automatic collector was introduced.

Both follow-up commands exited **0**:

| Command | Result / observable behavior | Evidence |
| --- | --- | --- |
| `./bin/dev check` | **493 tests, 3327 assertions**, Deptrac **669 allowed / 0 violations / 0 uncovered**; all checks pass | `var/test-runs/run-M1k0mj9O/checks.log` |
| `./bin/dev test` | **68 tests, 1421 assertions**, including **17 event tests / 733 assertions**; actual selective HTTP/CLI publication, unmapped buffer/native-lazy hydration and full PostgreSQL regression pass | `var/test-runs/run-cLhpQgCm/` |

Fresh follow-up reviewer `ses_f6b039770ffeTdpi6MFcPxWUtS` approved with no actionable
findings. This bounded refactor changes no bootstrap/dependencies/schema/transaction
coordination; its checks were `check` and `test`. The consumer run above is historical.

**Implementation verification and review, including the follow-up, are complete;
the user has accepted the result.**
Detailed findings and evidence belong in the Subtask 4 record. The following accepted
3b/3a/2 results remain historical evidence for their respective subtasks.

## Accepted Subtask 3b evidence

These are completed verification runs, not new tests run while preparing the
handoff. All exited **0** on **2026-09-11**:

- `./bin/dev setup`: migration current; development HTTP/PostgreSQL healthy.
- `./bin/dev check`: **264 tests, 1091 assertions**; Deptrac **534 allowed / 0
  violations / 0 uncovered**, audit/lint/PHPStan/style/shell checks pass.
  Evidence: `var/test-runs/run-A1hHkzXl/checks.log`.
- `./bin/dev test`: **51 tests, 681 assertions**, including 20 CQRS tests establishing
  cross-adapter success, validation, nested rollback, commit failure and ORM recovery.
  Evidence: `var/test-runs/run-ccxmS6wn/`.
- `TMPDIR=/tmp/opencode ./bin/dev verify-setup`: clean consumer repeatability,
  full E2E isolation and preservation of development data/history/credentials.
  Evidence: `/tmp/opencode/donmario-setup-WVo9gMEV/`.

Independent reviewers:
- Runtime: `ses_f6e5519eeffePIIpfywMFd0SDO` — approved.
- Boundaries: `ses_f6e551798ffeyJ7Izv8zkSGJUf` — approved after corrections for
  post-construction wiring replacement and public handler alias exposure.

The corrections changed only compiler guards/fixtures; `cache:clear` and full `check`
were rerun successfully, preserving debug/logger wiring. Runtime/E2E implementations
were unchanged; the existing PostgreSQL and consumer evidence remains applicable.
Safe logs and details are in the Subtask 3 record; runtime directories can contain
private settings and must not be published wholesale.

## Accepted Subtask 3a evidence

Completed on **2026-09-11**, both with exit **0**:

- `./bin/dev check`: **233 tests, 1004 assertions**, Deptrac **280 allowed / 0
  violations / 0 uncovered**, audit/lint/PHPStan/style/shell checks passed.
  Evidence: `var/test-runs/run-tsoSpXYc/checks.log`.
- `./bin/dev test`: **28 tests, 296 assertions** with actual HTTP/PostgreSQL,
  migrations/schema boundaries, ORM persistence, outage/recreation/recovery.
  Evidence: `var/test-runs/run-32r5L0jL/`.

These historical runs establish the accepted 3a boundary alignment, preceding 3b.
See the Subtask 3 record for exact behavior and review status.

Independent 3a reviewer: `ses_f6f346d5bffem59acqW8EJwnqp`; approved with no actionable
findings or blockers. The later runtime work has separate 3b evidence above.

## Accepted Subtask 2 evidence

These are Subtask 2's historical results, preceding the 3a public-data placement
change. They do not establish verification of the current 3a changes.
All commands exited **0**:

| Command | Result |
| --- | --- |
| `./bin/dev setup` | Migration current; application/database healthy |
| `./bin/dev check` | **195 tests, 794 assertions**; Deptrac **279 allowed, 0 violations, 0 uncovered**; audit/lint/PHPStan max/style passed |
| `./bin/dev test` | **28 tests, 296 assertions** using real HTTP/PostgreSQL |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Fresh consumer setup, interface-backed persistence and development/test isolation passed |

Evidence:

- `var/test-runs/run-PepVb2KJ/checks.log`
- `var/test-runs/run-tI2nptAZ/`
- `/tmp/opencode/donmario-setup-VfbtpG1d/`

Repository tests establish missing-ID null, `add()` without implicit flush,
caller-controlled flush, clear/reload, and persistent UUID/title across
database-container recreation. Migration failure/rollback/recovery and negative
architecture/schema cases also pass.

Independent correction reviewer: `ses_f6fe0f0f0ffeftBiGimEB3zjeO`.
All findings were resolved and rechecked. Detailed evidence and review history are
in `docs/tasks/02-module-persistence.md`.

Local execution artifacts may be temporary. Do not expose private settings or
publish whole evidence directories. Preserve `var/docker/local.env` and
development data.

## Accepted Subtask 4 conventions

The approved design and acceptance criteria are in `docs/tasks/04-synchronous-events.md`.
Current implementation guarantees and limits:

- Concrete events directly extend their exact empty abstract readonly category:
  `Platform/Event/BaseEvent -> DomainEvent, ApplicationEvent, InfrastructureEvent`.
  No primitive state, behavior, event IDs or metadata. Domain has the narrow
  pure-data DomainEvent exception and exact opt-in recording support permission,
  not general Platform access. All event data and
  primitives are excluded from services.
- Domain entities may use `Platform/Event/Recording/RecordsDomainEvents` and
  `RecordsDomainEventsTrait` to record protected internal facts and publicly release
  the batch. These helpers are non-service support, not public data. Application
  calls the entity's release method and selects facts explicitly; generic DomainEvent
  batches must not all be translated as creation events.
- Internal `Domain/Event` facts are translated to public `Application/<UseCase>`
  events. Our internal `Infrastructure/Event` and private `Infrastructure/EventListener`
  are distinct from `Infrastructure/Framework/<Library>/EventListener`. Our listeners
  accept exact public events and use public data, approved values, the handler
  declaration and exact CommandBus/QueryBus helpers, never repositories/handlers/ORM.
- Required effects use explicit nested command orchestration in the root transaction.
  Best-effort events run **after confirmed commit and root ORM/context cleanup**.
  Each listener command has a fresh independent transaction. Listener failures retain
  producer success and earlier committed effects, log safe metadata and continue.
- FIFO uses separate command/delivery state, with 100 events per root buffer and
  100 accepted per delivery session, including delivered/zero-listener events.
  Valid overflow is dropped and diagnosed; caps do not bound payload size or runtime.
- Recording requires healthy owned command-handler execution, no query or ORM
  lifecycle callback. Current Doctrine stack-frame detection has bounded coverage;
  it is not an arbitrary callback sandbox. ORM wrapInTransaction remains intact;
  context/rollback-only invalidation prevents caught failures from committing.
- Cleanup failure disables further runtime messaging/recording. After confirmed
  commit it preserves producer success and skips unsafe delivery. Safe logging failure
  cannot replace that success. Process failure may lose events; there is no outbox,
  durable retry/replay or automatic command retry after unknown commit outcome.

Full verification and fresh independent reviews are **complete**, with all findings
resolved and user acceptance recorded; final event-specific evidence is above.
The next approval gate is separate Subtask 5 discovery/design.

Durable/async delivery is a separate Subtask 5 design. Authentication, authorization
and the fuller TaskTracking demo retain their later approval gates.

Follow `AGENTS.md`:

discovery → design with acceptance criteria and security/performance review →
user approval → implementation → actual container/PostgreSQL verification →
fresh independent review → user acceptance.

Use `./bin/dev` for Composer, console commands, checks and tests. Do not rerun
unchanged passing suites merely to resume a session. Run appropriate checks for
new changes or unresolved concerns.

## Fresh-session prompt

> Read `docs/handoff.md`, `docs/tasks/04-synchronous-events.md` and the referenced
> project instructions. Subtasks 1, 2, 3a, 3b and 4 are accepted, including the opt-in
> recording refactor. Begin separate Subtask 5 discovery and propose its design with
> acceptance criteria and security/performance review; obtain approval before
> implementation. Preserve accepted uncommitted work
> and current Application co-location, exact category/module boundaries and explicit
> required-command/best-effort postcommit conventions.
