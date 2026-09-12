# Subtask 4 — layered events and best-effort postcommit delivery

## Approval and status

The user approved the revised design and instructed **“start with category only
primitives, accept and proceed”**. Implementation and full verification are complete.
Fresh independent reviewers approved the corrected implementation. The user then
approved the bounded opt-in recording follow-up below; verification and its fresh
independent review also passed. **The user accepted the completed Subtask 4, including
the recording refactor, with “i accept”.** Next is separate Subtask 5 discovery/design;
outbox/async implementation requires its own design approval.

This replaces the earlier unapproved precommit/Contract proposal. Subtasks 1, 2,
3a and 3b remain accepted. Their existing modified/untracked work is preserved;
this subtask extends it without a commit or push.

## Approved decisions

- No `Contract` directory. Commands, queries, results and public Application
  events are co-located at `Application/<UseCase>/<Name>{Command,Query,Result,Event}`.
- Internal Domain facts live in `Domain/Event/<Name>Event`. Application explicitly
  translates selected facts to public Application events; Domain cannot depend
  on Application data.
- Our internal technical events live directly in `Infrastructure/Event`; our
  Application-event listeners live directly in `Infrastructure/EventListener`.
- Adapters to library events are distinct:
  `Infrastructure/Framework/<Library>/EventListener`. Vendor event classes retain
  vendor ownership and are not automatically our Application-bus messages.
- Category-only abstract readonly primitives live in `Platform/Event`:
  `BaseEvent`, with direct children `DomainEvent`, `ApplicationEvent`, and
  `InfrastructureEvent`. They have no state, constructors, behavior or metadata.
  Concrete events are final readonly and directly extend their matching category.
- Required actions use explicit command orchestration inside the root transaction.
  Existing nested-command failure/rollback and repository no-flush rules apply.
- Application event delivery is synchronous, **after confirmed commit and root
  ORM/context cleanup**, best effort. Each listener command starts a fresh root
  transaction. Listener failure does not undo the producer or earlier successes.
- Preserve the producer result, log safe listener diagnostics, and continue other
  listeners/events. Process failure may lose undelivered in-memory events.
- Durable outbox obligations/retries/replay are a separate later subtask.

## Discovery and references

Read AGENTS, README, architecture, roadmap, handoff, task records 02/03 and the
Composer/Flex manifests and locks. Messenger/Validator 8.1.6, Doctrine ORM 3.7.0
and DBAL 4.4.4 already support the synchronous design; no new package is required.

References: [Symfony 8.1 Messenger](https://symfony.com/doc/8.1/messenger.html),
installed `EntityManager::wrapInTransaction`, Messenger `HandleMessageMiddleware`
and the existing invocation/transaction middleware.

Discovery compared explicit transactional orchestration, precommit listeners,
postcommit inline delivery, outbox with inline delivery and worker-first outbox.
The user chose explicit required orchestration plus best-effort postcommit delivery.
Synchronous timing, database atomicity and durable recovery are separate guarantees.
ORM postFlush is not physical root postCommit. Deferred-dispatch stamps are an
in-memory ordering mechanism, not durable publication.

## Implementation design

### Categories, contracts and boundaries

Source/Deptrac/container classification must agree on exact location and direct
category parent. Only concrete public Application events enter
`application.event.bus`; command/query buses retain their exact message-kind and
single-handler policies. Broad abstract event types cannot hide arbitrary public
payloads. Commands/queries/results retain the no-inheritance data rule.

All event data and primitives are excluded from services. The exact DomainEvent
primitive is a narrow pure-data dependency exception for Domain, not access to
Platform services. Public payloads cannot expose Domain/Infrastructure events,
entities, repositories or command/query/result DTOs.

Private `Infrastructure/EventListener/*Listener` services declare one class-level
`#[AsMessageHandler(bus: 'application.event.bus')]` (optional integer priority),
and public non-static `__invoke(ExactApplicationEvent): void`. Zero or multiple
distinct listeners are valid. Their dependencies are limited to public data,
approved values, the exact command/query helpers and the handler declaration.
Own-module repositories, handlers, ORM and raw buses remain forbidden. Framework
listeners do not automatically receive this permission or bus registration.

Reconcile source declarations and effective registrations, and preserve reviewed
privacy, alias-chain, same-class-copy, method-call, shared-state and middleware
wiring protections. Classification is a build-time guardrail, not a PHP sandbox.

### Production journey

`Task` records a module-local Domain TaskCreatedEvent. `CreateTaskHandler` schedules
the Task through its Domain repository, releases its recorded facts and maps the
creation fact to `Application/CreateTask/TaskCreatedEvent(Uuid $taskId)`.
`ApplicationEventRecorder::record(object)` validates and buffers it.

No generic aggregate scan or ORM publication callback is introduced. Category
primitives carry no universal event ID, timestamp or delivery metadata. Domain
facts and public integration facts remain separate identities.

### Transaction and delivery lifecycle

1. Root scope, message policy and YAML validation behave as in 3b.
2. A valid root command owns the default ORM transaction via `wrapInTransaction`.
   Explicit nested commands share it and their failures invalidate the root even
   when caught. The root verifies its result and health, flushes and commits.
3. Application event records accumulate per root command. Recording requires a
   healthy owned command/handler scope, with no enclosing query. Invalid kinds or
   scope are programming errors and invalidate an active root before rethrowing.
   Messaging/recording from ORM flush callbacks is rejected; caught violations
   must still prevent physical commit.
4. After successful downstream completion, detach its pending event batch, leave
   the invocation and reset context/EntityManager. Failed/unconfirmed commits
   discard the batch; no listener executes along that failure path.
5. Pass the batch to a separate best-effort delivery session. It owns the FIFO
   and drain flag independently of command state. The private bus has only delivery/
   exact-message policy, bus-name stamp and standard zero-or-more handling.
6. Listener commands are fresh roots and independently commit/reset. Their events
   append to the active delivery queue rather than recursively dispatch. For a
   producer batch A/B with A producing C, delivery is A/B/C.
7. Standard Messenger attempts all current-event listeners and aggregates their
   failures. Safe diagnostic metadata is logged and delivery continues. A successful
   listener command's events survive a later listener exception; failed command
   batches are discarded without deleting other committed work's events.
8. Return the original producer result after best-effort delivery. Subscriber
   validation failures do not become original HTTP422/CLI input diagnostics.

Implementation bounds: at most 100 buffered events per root and 100 accepted
events per delivery session, counting already delivered and zero-listener events.
Valid overflow is dropped with a summarized diagnostic, not a rollback of business
work. Invalid event/scope use remains a programming error. Caps do not bound
arbitrary handler runtime or payload bytes.

If ORM cleanup fails after a confirmed commit, preserve that committed result,
skip unsafe delivery and reject further work on the contaminated runtime. Logging
failure must not replace a committed producer result. Process death/OOM cannot
promise an eventual response. Connectivity loss during COMMIT can still mean an
unknown outcome; there is no automatic command retry.

### Adapters and observability

The producer's existing HTTP201/UUID/Location and CLI UUID/exit0 survive subscriber
failure. Original input validation and definite producer failures retain 3b behavior.
Log only safe event/listener identity, exception class and dropped counts; never
event payloads, arbitrary exception messages, SQL, credentials or exception objects.

## Security and performance review

Minimal UUID payloads and exact data/listener permissions preserve module ownership.
Public events are integration facts, not authorization grants. Required security
effects remain explicit transactional operations. Tests use disposable PostgreSQL
and fixed verification configuration, never client-controlled fault flags.

Only each command's participating database effects are atomic. External HTTP/email/
filesystem effects cannot be rolled back. Best-effort publication permits lost
events and partial completion; no durability, replay or deduplication is claimed.

Postcommit delivery releases producer locks before subscribers run. It adds no
event-driven flushes to the producer. Listener command transactions still add
latency before HTTP/CLI return. FIFO iteration and finite counts bound event cascades;
root cleanup bounds cross-invocation state. State assumes sequential execution,
not concurrent fibers sharing one container. No ordinary-request schema inspection
or transport polling is added.

## Acceptance criteria

1. Primitive/category/path/payload positives and negatives prove internal Domain,
   public Application and internal Infrastructure event separation. Obsolete
   Contract paths, wrong parents and arbitrary DTO inheritance fail.
2. Real source/Deptrac/compiled fixtures distinguish our listeners from framework
   listeners and reject service/persistence/raw-bus/alias/method-call bypasses.
3. Real HTTP/CLI creation records the Domain fact, translates it, commits and
   retains its accepted result contract; zero subscribers succeeds.
4. A disposable physical EventObserving module consumes the public event through
   its Infrastructure listener and co-located command/Domain port/owned table.
   Owning and independent SQL prove producer commit before listener execution.
5. Producer/A/C effects remain committed while listener B's failed command rolls
   back. Required explicitly nested failure still rolls back the whole root and
   emits no events. Definite commit failure emits nothing.
6. Same-process held buses/recorder/repository/lazy EntityManager recover after
   listener failures without stale events, transaction state or managed entities.
7. FIFO chaining, successful-command events surviving later listener failure,
   failed-command batch discard, zero listeners and finite limits are observed.
8. Subscriber failure leaves actual HTTP201 and CLI0; diagnostics omit sensitive
   data. Query/recording restrictions, cleanup and logging failure paths are tested.
9. Full container checks and PostgreSQL outage/recovery plus consumer isolation
   pass. Fresh independent implementation reviewers resolve findings, followed by
   user acceptance before the next subtask.

## Verification plan and evidence

Use only the container-first tooling and record exact commands/results:

```sh
./bin/dev setup
./bin/dev console debug:messenger
./bin/dev check
./bin/dev test
TMPDIR=/tmp/opencode ./bin/dev verify-setup
```

### Completed pre-review verification

All final commands below exited **0**:

| Command | Result / observable behavior | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Existing migration current; development PostgreSQL and HTTP healthy | `http://127.0.0.1:8080` |
| `./bin/dev console debug:messenger` | Three explicit buses compile; ordinary TaskTracking command/query handlers retained, production event has zero subscribers | Container console output |
| `./bin/dev check` | **398 tests, 2530 assertions**; Deptrac **630 allowed / 0 violations / 0 uncovered**; audit, container/YAML/Twig lint, metadata, PHPStan, Symfony style, syntax and shell contracts pass | `var/test-runs/run-a74ftcjr/checks.log` |
| `./bin/dev test` | **64 tests, 1207 assertions**, including **13 event tests / 526 assertions**; actual HTTP/CLI/PostgreSQL, listener rollback/independent commit, FIFO/bounds, held-service recovery and database outage/recreation/recovery | `var/test-runs/run-ALgEGYNN/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Clean consumer setup/repeatability, complete E2E including event fixtures, unchanged development marker/history/credentials and isolation checks | `/tmp/opencode/donmario-setup-slS8RIVp/` |

The generated EventObserving application passes production source/Deptrac/container,
mapping/migration and live-schema checks. Its real HTTP server and CLI execute the
normal TaskTracking adapters. Independent SQL sees the producer before subscriber
commands, and distinct PostgreSQL transaction IDs establish independent listener
roots. Failed listener writes are observed on their owning connection before rollback;
producer and following listeners remain committed. Fixture migrations remove their
table before subsequent normal persistence/outage phases.

Initial integration failures were resolved before the passing runs:

- Explicit Messenger handling needed the child-definition `index_1` override for
  zero-handler permission; positional additions did not override that argument.
- Listener inventory now ignores abstract/excluded Symfony instanceof templates
  while still rejecting duplicate concrete services and registrations.
- Compilation negatives now mutate effective middleware definitions at the correct
  phase. Safe listener-name matching and a real named-listener fixture replaced
  an unsuitable closure alias expectation.
- The reflection helper's generic PHPDoc now accepts concrete reflected classes;
  this last correction changes static typing only, not the passing runtime.

Nonpassing diagnostic runs: `run-OlEMQad9`, `run-jtK1S5hT`, `run-Hsg9aH7P`.
These are not successful evidence. Evidence directories can contain private settings;
only redacted logs are suitable for sharing.

### Independent review and resolutions

Fresh independent reviewers:

- Runtime/transaction/delivery: `ses_f6b6b07d8ffeyOaY0gqScoL2ud` — **approved after corrections**.
- Source/container boundaries: `ses_f6b6b06d7ffeaMjEEM2a0gEQNP` — **approved after corrections**.

Three findings were resolved:

1. **Listener construction failure skipped later listeners of the same event.**
   Messenger resolved lazy handler services while advancing its iterator, outside
   its per-handler catch. After validating all original descriptors, the compiler
   now installs an inline `EventListenerInvoker` with a Symfony service closure.
   Resolution and invocation both occur inside standard Messenger handling. Logical
   listener aliases retain distinct handling/diagnostic identities. The DI exception
   is restricted to the exact event-map descriptor, wrapper, closure and checked
   private listener; named/copy/reused/rewired wrappers are rejected. A real compiled
   fixture plus HTTP/CLI/PostgreSQL prove A/C commit when B's constructor fails.
2. **Same-module listeners could call each other through an ignored intra-layer
   Deptrac dependency.** `event.listener_dependency` rejects statically named
   listener-to-other-listener references, including aliases, construction, static
   access, inheritance, types and attributes. The regression explicitly proves the
   source guard rejects the call even though Deptrac reports no intra-layer violation.
3. **Caught final-flush rejection needed actual commit evidence.** Two PostgreSQL
   scenarios run real ORM `postFlush` callbacks through the production transaction
   boundary. They observe the producer SQL locally and not independently, catch a
   recording/command-dispatch rejection, and return normally. DBAL rollback-only
   still causes `CommitFailedRollbackOnly`; no producer rows/events commit, and the
   same held services recover on the next command.

Both reviewers rechecked the fixes, narrow wrapper permission and updated evidence,
found no additional actionable regressions, and approved their scopes. The subsequent
clean-consumer verification also passed. No required check or review finding remains
outstanding. The user subsequently accepted the completed implementation and follow-up.

### Final post-review verification

All commands exited **0**:

| Command | Result / meaningful behavior | Evidence |
| --- | --- | --- |
| `./bin/dev check` | **429 tests, 2825 assertions**; Deptrac **651 allowed / 0 violations / 0 uncovered**; full audit/lint/metadata/static/style/syntax/shell checks pass | `var/test-runs/run-FBkwdQNX/checks.log` |
| `./bin/dev test` | **67 tests, 1327 assertions**, including **16 event tests / 646 assertions**; real HTTP/CLI/PostgreSQL constructor failure, caught final-flush rollback, independent listener commits, FIFO/bounds, held-service recovery and full outage/recreation/recovery | `var/test-runs/run-LGHB90if/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Post-fix clean consumer setup/repeatability and complete E2E; development marker/history/credentials and isolation preserved | `/tmp/opencode/donmario-setup-g66L7xZX/` |

The final check phase took about 40 seconds and event PostgreSQL tests about 28 seconds
on this host; these are observations, not performance guarantees. Only documentation
was finalized after these passing checks and independent re-review.

## Approved follow-up — opt-in Domain event recording

After a read-only design challenge and discussion of aggregate/base-entity patterns,
the user approved the interface/trait refactor and then said **“ok, i accept
recommendations, proceed”**. The approved scope is:

- Pure `Platform/Event/Recording/RecordsDomainEvents` interface with
  `releaseEvents(): array` returning `list<DomainEvent>`.
- Opt-in `RecordsDomainEventsTrait` with a private transient buffer, protected
  `recordDomainEvent(DomainEvent): void` and public draining `releaseEvents()`.
- `Task` explicitly implements the interface and uses the trait. Its UUID and
  mappings stay module-owned; no BaseEntity/BaseAggregateRoot or version column.
- Application explicitly selects Domain TaskCreatedEvent for translation, rather
  than assuming every released fact represents creation.
- Exact support-type dependency permissions and service-registration exclusions;
  recording support is not public data and does not alter the four empty primitives.

This is a capability, not a compulsory entity superclass or automatic collector.
Event release transfers facts and clears the local buffer; it is not publication,
commit acknowledgement, or durable history. IDs and optimistic locking remain
separate future design decisions, with concurrency introduced alongside a meaningful
update journey and conflict handling.

Security/performance: the helpers have no ORM, dispatcher or service dependencies.
Only the two exact types are allowed to Domain implementation; public event payloads
and listeners do not gain access. The trait keeps the same per-object in-memory
collection semantics and adds no SQL, schema changes or automatic scanning.

Acceptance: independent objects/batches retain FIFO facts without replay; Task opts
in; real Doctrine reload/native-lazy initialization has an empty unmapped buffer;
an extra internal fact does not create another public creation event through actual
HTTP/CLI/PostgreSQL; source/DI negatives preserve module/data/primitive boundaries.
Run `./bin/dev check` and `./bin/dev test`, then obtain fresh independent review,
resolve findings and request acceptance. Prior evidence above predates this follow-up.

### Follow-up verification

All final commands exited **0**:

| Command | Result / behavior | Evidence |
| --- | --- | --- |
| `./bin/dev composer exec -- php-cs-fixer fix --sequential` | No formatting changes needed | Container output |
| `./bin/dev check` | **493 tests, 3327 assertions**, Deptrac **669 allowed / 0 violations / 0 uncovered**; all audit/lint/static/style/syntax and shell checks passed | `var/test-runs/run-M1k0mj9O/checks.log` |
| `./bin/dev test` | **68 tests, 1421 assertions**, including **17 event tests / 733 assertions**; actual HTTP/CLI selective publication, reload/native-lazy empty buffers and full PostgreSQL outage/recovery passed | `var/test-runs/run-cLhpQgCm/` |

The selective-publication fixture adds a second, nonpublic Domain fact to Task
creation. HTTP/CLI and held-service flows still create exactly one public creation
effect per subscriber. Ordinary and lazy Doctrine reloads produce no new Domain
creation fact, and metadata excludes the trait's buffer. Generated entities prove
independent opt-in use without becoming public data; source/DI negatives retain
the empty primitive and exact support dependency/service policies.

The first check run passed PHPUnit but found a string/reflection annotation issue
in a new negative-test helper. Loading the interface/trait with their corresponding
existence checks corrected that helper; the full check then passed. Runtime and E2E
code did not change after the successful PostgreSQL run. Initial diagnostics:
`var/test-runs/run-h2SmbwRr/checks.log` (not passing evidence).

This bounded follow-up changes no bootstrap, dependencies, schema or transaction/
delivery coordination. Its agreed checks are `check` and `test`; the earlier
clean-consumer results above remain explicitly historical.

### Follow-up independent review

Fresh read-only reviewer `ses_f6b039770ffeTdpi6MFcPxWUtS` **approved** the bounded
follow-up with no blockers or actionable findings. The review confirmed recording/
release semantics, selective mapping, exact support permissions, service/data
classification, unmapped hydration behavior and the matching execution evidence.
No additional implementation or verification was requested. Documentation alone
was finalized after the passing checks/review. The user accepted the completed
Subtask 4 including this follow-up. No outstanding findings or checks remain.
