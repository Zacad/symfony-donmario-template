# Subtask 3 — Application public data, CQRS and transactions

## Approval and delivery gates

On **2026-09-11**, after CQRS discovery, the user requested that commands, queries
and results live close to handlers in Application. The user explicitly selected:

1. **By use case:** one Application folder contains a use case's message, handler
   and useful result data.
2. **Keep public data API:** other modules may reference those data types and use
   them through buses; handlers and other services remain module-internal.

The user then said **"proceed"** to the bounded documentation/boundary alignment
plan. Delivery is split to preserve the project approval gates:

After implementation, container/PostgreSQL verification and independent review,
the user **accepted Subtask 3a on 2026-09-11** and requested continuation to the
separate Subtask 3b design gate.

The user **accepted Subtask 3b on 2026-09-11** after implementation, actual
container/PostgreSQL/consumer verification, independent reviews and resolution of
both boundary findings. The user requested a handoff for a fresh session.
**Current continuation: [Subtask 4](04-synchronous-events.md), including the opt-in
recording refactor, is implemented, verified, independently reviewed and user-accepted.
The subsequent native EventBus [Subtask 5b](05b-native-event-bus.md) is implemented,
verified, independently reviewed and user-accepted. Next is Subtask 6 discovery/design.**

The 3a/3b decisions and evidence below are historical. Subtask 4 supersedes their
`Contract/Event` direction and extends the two-helper boundary with exact event-category,
Application recorder and private listener permissions; see current architecture.

| Part | Status |
| --- | --- |
| Subtasks 1 and 2 | Accepted |
| 3a — Application public-data boundary alignment | Accepted by the user on 2026-09-11 after implementation, container/PostgreSQL verification and independent review |
| 3b — Messenger, validation, shared adapters and transactions | Accepted by the user on 2026-09-11 after implementation, verification and independent review |

## 3a — approved decisions

```text
src/Module/TaskTracking/
  Application/
    CreateTask/
      CreateTaskCommand.php
      CreateTaskHandler.php
    GetTask/
      GetTaskQuery.php
      GetTaskHandler.php
      GetTaskResult.php
  Domain/{Task.php,TaskRepository.php}
  Infrastructure/Persistence/DoctrineTaskRepository.php
  Resources/config/services.yaml
```

Part 3a established the naming/data/dependency/service rules through executable
fixtures. The use cases above were subsequently implemented in 3b.

- Public Application data has the exact path
  `Application/<UseCase>/<Name>{Command,Query,Result}.php`, with descriptive
  PascalCase names and a nonempty prefix before each suffix. Those suffixes are
  reserved for data within Application. Other Application classes remain internal.
- Public data is a module API role. Its physical placement no longer requires
  `Contract/Command`, `Contract/Query` or `Contract/Result`; those old paths fail
  source checks. Public events continue in `Contract/Event`.
- Public data retains the final-readonly DTO / literal-backed-enum restrictions,
  approved value types, empty constructors and absence of behavior/attributes.
  Application data can reference declared public data. Event data can reference
  approved values and public event data only.
- Domain cannot reference Application messages/results or handlers. Application
  maps domain entities/values to result data. Repositories remain Domain ports,
  implemented by module-local Infrastructure adapters that do not flush/commit.
- Public data is excluded from Symfony services; neighboring handlers remain
  private/autowired/autoconfigured services. Explicit or inline DTO definitions are
  rejected. Application data exclusions are scoped so console commands remain services.
- A result DTO is optional. The 3b implementation uses `CreateTaskCommand -> Uuid` and
  `GetTaskQuery -> GetTaskResult|null`. Results are independent of HTTP/CLI formatting.
- Framework/Platform exceptions for bus integration are recorded separately under
  3b below; the general direct Platform/module service boundaries still apply.

### Implementation coverage

`src/Platform/Architecture/ContractTypes.php` provides the common classifier for
source, Deptrac, service inventory and compiled DI. It replaces the dev-only
classifier previously under `tools/Architecture`.

Deptrac has separate non-overlapping Application-data, event-data and Application
implementation layers per module. Domain and event-data dependencies on Application
data are rejected. Data shape is validated by the source checker, separately from
the namespace-based dependency graph.

The early inventory pass rejects data registration before autowiring and omits
valid public data from required-service discovery. The late DI pass rejects data
definitions, including inline definitions and canonicalized class names. Ordinary
service/default/port and metadata/schema guarantees retain their documented bounds.

This step does not establish message/handler cardinality, bus dispatch, validation
mapping loading, transaction behavior or query purity. Those are 3b acceptance items.

### Security and performance review

The primary risk is inadvertently making the whole Application layer public or
exposing service behavior under a data suffix. Separate graph layers, strict data
shape validation, service-registration rejection and negative fixtures address it.
Domain/event restrictions prevent an indirect dependency on Application results.
Existing data-ownership and same-module Domain-port rules continue to apply.

Classification is deterministic and runs during source checking/container
compilation. It adds no ordinary-request SQL or schema inspection. These guards
remain build-time checks, not a runtime sandbox. All PostgreSQL tests use the
existing isolated test workflow and application role.

### Acceptance and evidence plan

- Real source/Deptrac positives for foreign public commands, queries, results,
  event data and new-module discovery.
- Negative cases for obsolete/misplaced data, mutable/behavior-bearing DTOs,
  entity exposure, foreign handlers/helpers, own neighboring handler exposure,
  Domain-to-Application data and event payload backreferences.
- Actual Symfony compilation in two generated modules: data excluded, neighboring
  handlers private and callable, injected Domain service working, result data
  propagated and console adapters retained.
- Missing handlers and explicit/inline/aliased data-service registrations fail
  with specific diagnostics; foreign handler aliases/locators remain forbidden.
- `./bin/dev check`: complete offline/compiled checks, audit, static analysis and style.
- `./bin/dev test`: actual HTTP/PostgreSQL regression, persistence, migration/schema
  negatives, outage, recreation and recovery.
- Fresh independent review, resolution/reverification and user acceptance before 3b.

### Executed evidence

All commands below exited **0** on **2026-09-11**:

| Command | Result and observable behavior | Evidence |
| --- | --- | --- |
| `./bin/dev composer exec -- php-cs-fixer fix --sequential` | Symfony formatting; adjusted import order in `ModuleServicesPassTest.php` | Containerized execution |
| `./bin/dev check` | **233 tests, 1004 assertions**; Deptrac **280 allowed, 0 violations, 0 uncovered**; audit, container lint, source/data checks, PHPStan max, style and shell contracts passed | `var/test-runs/run-tsoSpXYc/checks.log` |
| `./bin/dev test` | **28 tests, 296 assertions**; actual HTTP/PostgreSQL migration/schema/ORM checks, outage, database-container recreation and recovery passed | `var/test-runs/run-32r5L0jL/` |

The architecture fixture executes a handler with its real autowired Domain service,
constructs the co-located query/result objects and observes `domain-service:input`.
This passes for two discovered modules using the production module service prototype.
The command/query/result classes are loadable but absent from the compiled service
container, while console adapters resolve and return `console-adapter`.

The PostgreSQL suite observes the same committed Task UUID/title after database
container recreation and verifies add-without-flush, migration rollback/recovery
and schema-boundary failures. This is regression evidence for the 3a integration;
it does not claim Messenger/transaction-boundary execution, which belongs to 3b.

Checks took approximately 21 seconds, including 12 seconds of architecture PHPUnit
on this host while E2E ran concurrently. This is an observation, not a performance
guarantee. Unique test resources were cleaned up by both successful workflows.

### Independent review

Fresh read-only reviewer: `ses_f6f346d5bffem59acqW8EJwnqp`.

The reviewer approved Subtask 3a with **no actionable findings or blockers** after
examining the relevant implementation, tests, documentation and safe evidence logs.
The review confirmed exact-depth public-data classification, non-overlapping
Deptrac layers, Domain/event independence from Application data, early/late
data-service rejection, private-handler/console discovery, and meaningful compiled
fixture coverage. No new security-boundary broadening or request-time database work
was identified; documented runtime/dynamic-analysis limitations remain explicit.

No implementation changes or further verification were requested. Subsequent
changes at that gate were acceptance/design documentation; subsequent 3b runtime
changes are covered by their separate evidence below.
**The user accepted 3a on 2026-09-11. Subtask 3b's separate approval, implementation
and acceptance are recorded below.**

## 3b — approved design and implementation

After accepting 3a and creating the requested local checkpoint commit `41b368f`,
the user said **"proceed"** to the presented 3b design/implementation gate on
**2026-09-11**. The design below is approved and implemented. Verification and fresh
independent review are complete, and the user accepted 3b on **2026-09-11**.

### Discovery and dependency changes

At discovery, the locks and Symfony 8.1 APIs showed Messenger and Validator were
absent; Doctrine ORM 3.7.0, DBAL 4.4.4 and Doctrine bridge 8.1.6 were already installed.
The existing Task had a generated UUIDv7 and a 1–200-character, nonblank title
invariant, with a Domain repository port supplying `add(Task)` and `find(Uuid)`.

The approved installation command was subsequently executed successfully, as
recorded in the implementation evidence below:

```sh
./bin/dev composer require 'symfony/messenger:8.1.*' 'symfony/validator:8.1.*' --no-interaction
```

Review the actual Flex recipes and resulting locks. Configure synchronous in-process
buses explicitly rather than retaining recipe transport examples or transport DSNs.
The existing schema and repository interface already support the proposed use cases.

Relevant references:

- [Symfony 8.1 Messenger](https://symfony.com/doc/8.1/messenger.html), including
  multiple buses, validation, handler results and Doctrine middleware.
- [Symfony 8.1 validation](https://symfony.com/doc/8.1/validation.html).
- Installed `DoctrineTransactionMiddleware` rolls back without itself discarding
  pending ORM state after a handler exception. ORM `wrapInTransaction()` closes the
  manager on failure after transaction startup; startup and reset handling still
  need an outer lifecycle boundary.
- `HandleTrait` checks the handled-stamp count after dispatch returns. That alone
  would detect duplicate command handlers too late to prevent a commit.
- Symfony's default middleware includes deferred-dispatch/sender behavior. An
  explicit synchronous stack makes the ordering below directly inspectable.

### Use cases and result contracts

Implement the co-located structure already agreed in 3a:

| Message | Handler | Return |
| --- | --- | --- |
| `Application/CreateTask/CreateTaskCommand(string $title)` | `CreateTaskHandler` | `Uuid` |
| `Application/GetTask/GetTaskQuery(string $id)` | `GetTaskHandler` | `GetTaskResult\|null` |

`GetTaskResult` contains `Uuid $id` and `string $title`. Creation constructs a Domain
Task and schedules it through `TaskRepository::add()`. Lookup converts the validated
UUID, calls `find()` and maps the entity into result data. Both handlers inject the
Domain interface; HTTP/CLI adapters inject buses. A missing valid UUID produces null
at the application boundary, leaving presentation to the adapter.

### Buses, registration and results

Use Symfony Messenger's `command.bus` and `query.bus`, both synchronous, with
private handlers registered using explicit `#[AsMessageHandler(bus: ...)]` attributes.
The supported handler shape is a public `__invoke()` taking one exact message DTO,
with a declared return type. Handler and message belong to the same module/use-case
folder. Query returns are approved public/value data (nullable where appropriate);
commands can also return void. Domain entities and broad mixed/object results are
not public handler return contracts.

Two small technical helpers, `Platform/Messaging/CommandBus` and `QueryBus`, expose
`dispatch(object)` and `ask(object)` and extract results using Messenger primitives.
Their public input is a known command/query DTO, with no caller-supplied envelopes,
stamps, handler selection or validation groups. Each helper uses its explicit bus.
Handlers declare concrete return types; adapters check the expected result shape.

A compiler pass reconciles discovered message DTOs with Messenger's effective
registrations before services are removed/inlined. It validates:

- Exactly one effective handler for every public command/query DTO.
- Correct bus, owning module, co-location and supported callable signature.
- No namespace/global wildcard, interface-wide, union-message, transport-specific
  or batch registration that could widen/bypass the supported message mapping.
- The expected synchronous middleware order and exact facade-to-bus wiring.

Known framework-only registrations that cannot match an allowed application message
are not reclassified as module handlers. Runtime message policy rejects unknown
objects, events/results on these buses, wrong-bus dispatch and supplied stamps.
The command boundary also checks for exactly one handled result before flushing;
result extraction after dispatch cannot be the first cardinality check.

### Validation and middleware order

Register `TaskTracking/Resources/config/validation.yaml` explicitly through
`framework.validation.mapping.paths`. Keep DTOs data-only. Use Symfony's standard
validation middleware for both HTTP/CLI and direct bus callers.

- Title: valid UTF-8, no NUL, nonblank under the existing PHP `trim()` rule, at most
  200 characters. Length applies to the stored title; validation does not trim or
  otherwise rewrite it. Charset checking precedes other text constraints.
- ID: a valid standard UUID string, before conversion by the query handler.
- Adapters reject missing/extra fields and wrong JSON types before constructing DTOs.

The explicit logical order is:

```text
command.bus: invocation scope -> message policy -> bus-name stamp
             -> validation -> command transaction -> handle_message
query.bus:   invocation scope -> message policy -> bus-name stamp
             -> validation -> handle_message
```

The outer invocation scope tracks nesting and failure but opens no transaction.
Thus invalid top-level messages need no database access, while a failed nested
validation still invalidates an already-active parent command.

### Transaction and unit-of-work ownership

Use the default business EntityManager/connection. A small Platform transaction
middleware delegates the physical begin/flush/commit/rollback lifecycle to Doctrine
`wrapInTransaction()` and coordinates it with the invocation scope.

1. A valid outer command starts the physical transaction. An unexpected transaction
   opened outside the coordinator is rejected rather than silently adopted.
2. The handler schedules writes through Domain ports. Nested commands participate
   in this unit without independently flushing or committing.
3. Before the outer callback returns to Doctrine, verify the handled result and
   that the logical command has not failed. Doctrine then flushes and commits.
4. The initiating adapter receives success only after the outer commit. Results
   returned to a nested caller are provisional until that commit.
5. A nested command/query failure is recorded before rethrowing. Catching it inside
   application code does not permit the outer command to commit. This includes
   validation failures that occur before entering nested transaction middleware.
6. Handler/flush failures and definite database-aborted commits roll back the owned
   transaction and invalidate pending/managed ORM state. Handle transaction-start
   failure and failed connection cleanup as well; reset/close failed connection
   state and reset the manager through Doctrine's registry.
7. Independent root invocations finish with clean ORM/context state; nested calls
   never reset their parent's unit of work. A held repository's injected native-lazy
   EntityManager must work correctly after reset, proven in the same PHP process.

No automatic command retries are introduced. If connectivity is lost during COMMIT,
the commit outcome can be unknown; expose failure without claiming definite rollback
or replaying the command. Rollback semantics apply to participating database writes.

Queries perform no automatic flush or new write transaction. A command dispatched
from within a query is rejected. Queries nested in a command share its current
transaction/unit of work; they do not force unflushed changes into SQL. Independent
query completion discards managed state without flushing. Direct SQL/write behavior
inside arbitrary repository implementations remains subject to targeted tests/review;
this is not a database read-only sandbox. This step establishes command nesting;
event publication/flush ordering is separately designed in Subtask 4.

### Thin HTTP and CLI adapters

| HTTP (dev/test only) | CLI |
| --- | --- |
| `POST /_demo/tasks` with `{"title":"..."}` | `app:task:create <title>` |
| `GET /_demo/tasks/{id}` | `app:task:show <id>` |

Use Symfony's `env: ['dev', 'test']` route attributes and stateless JSON handling.
Creation returns HTTP 201 with `{"id":"..."}` and a Location header; lookup returns
200 with `{"id":"...","title":"..."}`. CLI creation writes the UUID, and lookup
writes JSON with escaped data and raw console output to avoid interpreting title
text as terminal/console formatting. Successful CLI operations exit 0.

| Condition | HTTP | CLI |
| --- | --- | --- |
| Malformed JSON, wrong/missing/extra fields | 400 | Command-line syntax uses Symfony Console diagnostics |
| Request body exceeds 4 KiB | 413 | Not an HTTP body concern |
| Content type other than `application/json` for creation | 415 | Not applicable |
| Message validation failure | 422 with field/message diagnostics | Exit 2 with safe diagnostics |
| Unknown valid task UUID | 404 | Exit 1 |
| Unexpected handler/database failure | Generic 500 | Generic error, exit 1 |

Known validation exceptions are distinguished from unexpected failures after the
bus/transaction has unwound. Responses and default CLI output omit SQL, credentials,
stack traces and echoed invalid values. Logs use safe operation/exception metadata.
Set no-store on demonstration responses. Body handling enforces the actual byte
limit, including requests without a usable Content-Length. Test that these routes
are absent from the production route collection.

### Narrow architecture-check integration

- Permit module Application/UI use of the two exact bus helper classes/services;
  keep Domain and public data independent of them. Validate helper-to-bus wiring.
- Reject direct module use/injection of raw Messenger buses, handler locators and
  middleware, including alias/inline bypass attempts. Permit the specific handler
  attribute and validation exception types needed by the supported adapters.
- Preserve private handler/data-service separation. Messenger's aggregate handler
  wiring is framework infrastructure, not a module-facing service locator.
- Use the 3a classifier for discovered message kind and public return types, avoiding
  a second competing module API inventory. Reconcile it with actual Symfony wiring.
- Test registered YAML constraints and middleware presence/order, including a missing
  mapping regression for the actual use cases. Extend module YAML lint coverage.

### Security and performance review

The HTTP surface is an explicit local development/test demonstration before the
authentication subtasks. It accepts fixed operations and typed fields, uses strict
JSON-only writes, enables no cross-origin access and does not rely on session cookies.
Production route exclusion is tested. Authorization remains a future use-case
boundary concern; public messages do not imply permission to execute them.

Bus validation occurs before opening a transaction. The 4 KiB HTTP body and 200-character
title bounds keep parsing/validation/storage bounded. Task lookup is by the UUID primary
key; creation adds one business insert plus transaction bookkeeping. Queries do not
perform existence/permission prefetches. Nested commands avoid independent transaction
commits. Unit-of-work cleanup bounds state retention across independent invocations.
The existing connection/DNS bounds remain applicable; no universal latency guarantee
or protection against arbitrary dynamic SQL is claimed.

Compile-time inventories and narrowly allowed technical wiring preserve module
ownership without per-request schema inspection. Fault injection uses test-only
kernel/middleware or disposable PostgreSQL fixtures, not client-controlled flags.

### Acceptance journeys

1. **Cross-adapter success:** create through HTTP and read through CLI, then reverse
   the entry points. Confirm generated UUID/title through an independent PostgreSQL
   connection after commit, and retrieve persisted data after database recreation.
2. **Validation:** empty/whitespace/oversized/invalid text and malformed UUIDs fail
   through HTTP, CLI and direct buses. Check boundary lengths and unchanged stored
   title. Invalid HTTP commands still return validation errors during database outage.
3. **Adapter contracts:** exercise content type, JSON shape, body-size enforcement,
   unknown IDs, escaped output, error redaction and production route absence.
4. **Single-handler/boundary enforcement:** missing, duplicate, wrong-bus/module,
   wildcard/unsupported handlers and raw bus/locator injection fail with specific
   diagnostics. Prove configured handlers receive the Domain repository port.
5. **Rollback:** test exceptions after persist scheduling, after actual SQL flush,
   and a PostgreSQL deferred failure at commit. Independent reads show no partial
   effects for these definite-failure cases.
6. **Nested atomicity:** nested success remains uncommitted until outer completion;
   outer or nested failure rolls all participating writes back, including a nested
   validation/handler exception deliberately caught by the outer handler.
7. **Same-process recovery:** reuse the compiled buses and injected repository after
   failed commands, then execute a successful command/query. Failed entities and
   logical failure/depth state cannot leak into later work.
8. **Query behavior:** result DTO/null propagation, no automatic flush/business
   writes, command-from-query rejection and correct nested scope preservation.
9. **Runtime/consumer regression:** real database outage/recovery and clean consumer
   setup, preserving migration history and development/test isolation.

### Verification and delivery plan

After approved implementation, run and record exact commands/results:

```sh
./bin/dev setup
./bin/dev console debug:messenger
./bin/dev console debug:validator 'App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand'
./bin/dev check
./bin/dev test
TMPDIR=/tmp/opencode ./bin/dev verify-setup
```

Extend the existing snapshot runners to include the new offline and real
HTTP/PostgreSQL journeys. Run appropriate checks once the changes are ready and
repeat only for changes, failures or unresolved review findings. Then obtain a
fresh independent subagent review, resolve/reverify findings, and request user
acceptance before starting the event subtask.

### Implementation and verification evidence

`./bin/dev composer require 'symfony/messenger:8.1.*' 'symfony/validator:8.1.*' --no-interaction`
exited 0, installing Messenger **8.1.6**, Validator **8.1.6**, Symfony Clock **8.1.0**
and PSR Clock **1.0.0** without updating existing packages. The Messenger and Validator
recipes were reviewed; transport examples/DSN were replaced by explicit synchronous
buses and module-owned validation mappings.

Initial cache compilation caught additional standard framework registrations for
console/process/HTTP messages. The checker now recognizes those exact framework
message classes as unreachable by the application DTO policy. Their presence in
`debug:messenger` does not expose them through the public helpers. Subsequent
`./bin/dev console cache:clear`, `./bin/dev console debug:messenger` and
`./bin/dev console debug:validator 'App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand'`
all exited 0, showing the two correctly bound TaskTracking handlers and all title
constraints. Native Console argument/option errors retain Symfony's normal syntax
diagnostics; use-case message validation explicitly returns exit 2.

The first full check found that runtime fixture services require Symfony's dumped
container build metadata. Those tests now boot actual isolated kernels. The first
E2E run exposed fixture alias ordering: module YAML had replaced the earlier test
binding. A verification-only pass now binds the fault adapter after configuration
and before autowiring, and tests assert the actual adapter identity. The assertions
also distinguish original nested failures from assertion failures. No fixture
switch is exposed by ordinary HTTP/CLI adapters.

The shared invocation context is a required, ordinary shared service: non-shared
replacement would separate failure tracking from the transaction. Its negative
compilation fixture and helper/middleware reference checks establish this invariant.

Completed on **2026-09-11**, all commands below exited **0**:

| Command | Result / meaningful behavior | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Existing migration current; development app and PostgreSQL healthy | `http://127.0.0.1:8080` |
| `./bin/dev console cache:clear` | Dev container compiles with standard debug tracing and explicit bus wiring | Console output |
| `./bin/dev console debug:messenger` | CreateTask on command.bus; GetTask on query.bus | Console output |
| `./bin/dev console debug:validator 'App\Module\TaskTracking\Application\CreateTask\CreateTaskCommand'` | Charset, NUL, blank and length constraints loaded from module YAML | Console output |
| `./bin/dev check` | **257 tests, 1084 assertions**; Deptrac **526 allowed, 0 violations, 0 uncovered**; audit, lint, PHPStan max, style and shell contracts pass | `var/test-runs/run-wlm3wYRM/checks.log` |
| `./bin/dev test` | **51 tests, 681 assertions**, including **20 CQRS tests / 370 assertions** using real HTTP/CLI/PostgreSQL | `var/test-runs/run-ccxmS6wn/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Fresh consumer setup and repeatability, unchanged credentials/history/ORM marker through restarts and the full isolated E2E suite | `/tmp/opencode/donmario-setup-WVo9gMEV/` |

Rollback tests observe flushed rows on the owning connection while independent
reads still see none, then verify zero committed rows after failure. Deferred
PostgreSQL trigger failure establishes commit rollback and generic HTTP/CLI errors.
Caught nested validation/handler/query failures invalidate outer writes; nested
success commits together. Held repository/native-lazy manager instances work after
failure and retain no failed entities. A deliberate native PDO/DBAL desynchronization
forces transaction-start failure and proves subsequent connection/state recovery.

Query tests prove nested reads preserve parent writes, commands inside queries are
rejected, and a fixture's scheduled query-side change is discarded without flush
or later leakage. Production route exclusion, wrong-message/envelope/stamp rejection,
module YAML metadata, unsafe handler wiring and public facade restrictions are
exercised against real Symfony containers. Outage input validation remains 422;
valid writes fail safely, and committed CQRS data survives database recreation.

The pre-review check took about 26 seconds (architecture PHPUnit about 15.5 seconds);
the CQRS PostgreSQL phase took about 1.9 seconds on this host. These observations
are not portable guarantees. Initial failed-run evidence is in
`var/test-runs/run-IHicDp95/` and `var/test-runs/run-iF0u1UuA/`; those runs stopped at
the fixture failures and are not passing evidence. Failed directories can retain
private settings and must not be published wholesale.

### Independent 3b review

Fresh read-only reviewers:

- Runtime/transactions/adapters: `ses_f6e5519eeffePIIpfywMFd0SDO` — approved with no
  actionable runtime findings after inspecting source and actual execution evidence.
- Compiler/source/DI boundaries: `ses_f6e551798ffeyJ7Izv8zkSGJUf` — identified two
  statically declared wiring gaps, then re-reviewed and approved both corrections.

Findings and resolutions:

1. **Post-construction wiring could replace a checked Messenger stack.** The
   standard-definition validator now rejects arbitrary method calls on checked
   buses, middleware, handler locators and handler descriptors. The sole exception
   is one ordinary handling-middleware `setLogger` call referencing the standard
   logger or Messenger logger. Actual compilation fixtures reject second constructor
   calls on buses, locators and descriptors, while standard dev tracing/logging works.
2. **A public alias could expose a private handler.** The pass now rejects public
   handler definitions and public aliases resolving through chains to effective
   handlers or same-class copies. Fixtures include a direct alias, an alias chain,
   a public definition and an alias to an untagged handler copy.

Reverification after these compiler-only corrections:

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev composer exec -- php-cs-fixer fix --sequential` | Exit 0; fixture imports formatted | Container output |
| `./bin/dev console cache:clear` | Exit 0; actual development compilation preserves standard tracing/logger wiring | Container output |
| `./bin/dev check` | Exit 0; **264 tests, 1091 assertions**, Deptrac **534 allowed / 0 violations / 0 uncovered**; audit/lint/PHPStan/style/shell checks pass | `var/test-runs/run-A1hHkzXl/checks.log` |

The boundary reviewer confirmed all findings resolved and requested no additional
implementation or verification. Runtime service implementations, adapters,
configuration and dependencies were unchanged by these corrections; the recorded
**51-test PostgreSQL/E2E run and clean-consumer verification remain applicable**.
Only documentation was finalized after re-review. No outstanding checks or findings
remain for 3b. **The user accepted Subtask 3b on 2026-09-11.** Current Subtask 4
completed verification/review and user acceptance are linked at the top of this record.
