# Subtask 9 — Policy-only authorization enforcement

> **Superseded authorization behavior:** This accepted policy-only record and all evidence
> below are retained as historical provenance. Current handlers use `#[Authorize]`, native
> module voters and the exact Platform public voter under the
> [clean Authorizing/native-voter/Task-ownership rework](10-authorizing-rework.md), not
> Application authorization policies or a policy locator. This accepted record remains
> historical provenance. The newly approved fresh-template four-table model supersedes
> the later verified intermediate reworks; its implementation, verification and fresh review
> are complete and user acceptance is pending.

## Approval and scope

The user rejected authorization checks in handlers: “i dont like the authorization in the handler”.
Following the policy-only proposal and its trusted-context, security/performance and
acceptance criteria, the user approved “accept and proceed”. This accepts the 8b
checkpoint and approves beginning this enforcement subtask. Git delivery was authorized
separately after acceptance, as recorded below.

Status (2026-09-16): **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED**,
including the actor-kind enum correction. The user's correction below supersedes
the original string-kind representation and its verification checkpoint.
Existing 8b implementation and evidence are preserved in this delivery.

### User acceptance — 2026-09-16

After the enum correction and an additional fresh independent design/implementation
review, the user said exactly:

> ok, i accept task, nest task will be continued in fresh session

This accepts Task 9 including the `ActorKind` correction. Task 10 — TaskTracking
use cases/CLI — will begin with discovery and design in a fresh session; its design
and implementation are not yet approved. This acceptance did not itself authorize a
Git commit or push; the subsequent explicit request below supplies that authorization.

### Git delivery authorization — 2026-09-16

The user subsequently requested exactly **“commit and push changes”**. This delivery
commit includes the accepted 8b Authorizing model/management and Task 9 enforcement,
the enum correction, tests and acceptance/handoff documentation. It targets `origin/main`;
Git history identifies the commit. No implementation changes followed final review,
and passing suites were not rerun for this Git-only delivery. Task 10 remains deferred
to discovery/design in a fresh session; future commits/pushes need fresh authorization.

## Approved design

- Every command/query handler declares one co-located private PHP policy through a
  handler-level attribute. Policies own all actor-dependent admission, including
  state-sensitive authorization. Handler bodies retain operation logic; Domain
  invariants and permission calculation remain their existing responsibilities.
- A compiler-validated policy map and exact middleware order enforce every invocation.
  Input validation precedes authorization; command authorization is inside the owned
  transaction before handler execution. Queries do not gain automatic transactions.
- Policies receive immutable trusted context, may read through QueryBus and owning
  Domain ports, and cannot dispatch commands/events. Context authority cannot change
  inside an invocation. A caught nested failure cannot authorize subsequent handler
  execution and nested denial retains rollback-only semantics.
- Native authenticated HTTP identity supplies the executing UUID, separately from
  message targets. Explicit operator adapters supply scoped accounts/assignments/tasks
  authority. Native password upgrades have an exact account-bound authentication scope.
  CLI or worker execution alone grants nothing. Missing identity denies protected calls.
- Foundational permission/existence queries have exact nonrecursive policies; there is
  no general disable switch for nested queries. Ordinary nested operations reauthorize.
- Initial-access delegation, filtered resource lists and durable service-account
  workflows retain separate designs. Existing production use cases and adapters are
  the enforcement scope; no new business management HTTP endpoints are introduced.

## Actor-kind correction

The user requested an enum instead of actor-kind string literals. `Actor::$kind` now
has the exact `ActorKind` enum type with `Anonymous`, `Account`, `Operator` and
`Authentication` cases. It is an unbacked enum because this is in-process execution
state, not a wire or persistence field. Context builders, policies, denial mapping
and verification fixtures use cases directly. `ActorKind` joins the exact non-service
authorization data classification; source/DI/Deptrac rules permit it in policies and
keep it out of public messages and ordinary module services.

### Completed correction verification and review — 2026-09-16

| Exact command | Post-correction result and evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys, dependencies and current migration; app/database healthy. |
| `./bin/dev check` | **PASS**, `var/test-runs/run-SSb9mtrr/`: **844 architecture tests / 4637 assertions + 516 unit tests / 3322 assertions = 1360 tests / 7959 assertions**, Deptrac **1887 allowed / 0 violations / 0 uncovered**. Audit, source/metadata, native fixture boots, PHPStan, style, syntax and shell checks passed. |
| `./bin/dev test` | **PASS**, `var/test-runs/run-0BEEcpKm/`: **230 tests / 6306 assertions**, all **46 PHPUnit phases**. Native HTTP, operator provisioning/management, authentication-scoped hash migration, nested policy/rollback behavior and database outage/recovery all pass with enum kinds. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-M4B96yAf/`; embedded **230 tests / 6310 assertions**, all **46 phases**, `application/var/test-runs/run-uTaVmkbI/`. Five authorization persistence and session-authorized/anonymous-denied Task HTTP checkpoints passed. |

Fresh independent correction reviewer **`ses_f574cd1afffet0VIFWAYaHvPMl`: APPROVED**
with no concrete findings, after comparing against the original verified consumer
and inspecting completed post-correction evidence. No suites were rerun by the
reviewer. All actor constructors/comparisons use cases; exact non-service/public-data
and policy-only boundaries have expanded source/DI/Deptrac coverage. The enum has no
wire/persistence contract. Dependencies/locks are unchanged. Final Docker inspection
found the development app/database healthy and no project test/consumer/worker
containers. Only docs changed after review. The following original evidence is
retained as history; these post-correction results are the current checkpoint.

### Additional fresh design and implementation review — 2026-09-16

At the user's explicit request, fresh reviewer **`ses_f5691c623ffeKmeUgmFSPqCWQ7`**
independently reviewed the full design and implementation, including the enum
correction, and **APPROVED** with no substantive findings or material coverage gaps.
The review covered all nine policies, actor trust/lifecycle, nested calls and foundation
read exceptions, middleware ordering/write restrictions/failure handling, source/DI/
Deptrac boundaries, transport errors, performance claims and meaningful verification.
The reviewer inspected final check/E2E/consumer evidence without rerunning suites or
editing files. Documented concurrency, live-read and future-work tradeoffs were
accepted as deliberate scope decisions. User acceptance followed this review.

## Security and performance

Live permissions remain authoritative. Same-transaction checking does not serialize
against revocation; already-authorized work may finish. Policy/handler reads may
overlap; use observed SQL budgets rather than assumed identity-map savings. Bus write
restrictions and source/DI guardrails are not arbitrary PHP/SQL sandboxes. Policy
constructors are side-effect-free; diagnostics exclude message and violation payloads.

## Acceptance criteria

1. Compilation rejects missing/invalid declarations, bypass wiring and invalid policy
   dependencies while preserving exact module/public-data boundaries.
2. Allowed/denied HTTP and CLI journeys use the same protected buses. Actor/target
   substitution and wrong-resource checks deny; registration/hash-upgrade bootstrap
   and self identity retain their approved behavior.
3. Actual PostgreSQL proves no denied handler effects and caught-nested rollback,
   live revocation, outage/recovery and independent execution-context cleanup.
4. Policies cannot dispatch commands/events, even through nested calls. Foundation
   reads terminate without recursive authorization or exposing arbitrary protected reads.
5. Setup, check, E2E and fresh-consumer verification pass. Fresh independent reviews
   follow final implementation and verification, before user acceptance.

## Completed implementation

`src/Platform/Authorization` contains the declaration, immutable Actor/PolicyContext,
private execution scopes and authorization middleware. `CqrsPass` compiles the exact
message-to-policy native locator and enforces middleware/signature/service contracts.
Source, Deptrac and resolved-DI checks classify policies separately and reject handler
or policy injection/calls, mutable context access and unapproved execution-facade use.
All nine existing command/query handlers have co-located policies.

| Use case | Admission |
| --- | --- |
| Register account | Explicit `accounts` operator |
| Upgrade password hash | Authentication scope bound to the exact account UUID |
| Get account identity | Authenticated account querying itself |
| Evaluate permissions | Policy-support read or `assignments` operator |
| Check account existence | Policy-support read or exact caller EvaluatePermissions/ChangeAccountAssignments |
| Change/list assignments | Account with global `authorizing.manage`, or `assignments` operator |
| Create Task | Account with global create permission, or `tasks` operator |
| Get Task | Account with view permission for the exact Task, or `tasks` operator |

Dev/test Task HTTP uses native web sessions and fixed denial JSON (401 anonymous,
403 authenticated). Operator CLI contracts and native password migration are retained.
Policy resolution and execution are both within the restrictive scope. Caught support
failures invalidate admission for root queries as well as root commands. Independent
operations reset invocation state and restore scoped authority.

## Original verification — 2026-09-15 (before enum correction)

| Exact command | Result and evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; existing RSA3072 keys, dependencies and migration retained; app/database healthy. Composer/Flex locks unchanged. |
| `./bin/dev check` | **PASS**, `var/test-runs/run-1nmJjuRE/`: **819 architecture tests / 4453 assertions + 516 unit tests / 3322 assertions = 1335 tests / 7775 assertions**; Deptrac **1878 allowed / 0 violations / 0 uncovered**; audit, metadata, PHPStan, style, syntax, shell and native fixture checks passed. |
| `./bin/dev test` | **PASS**, `var/test-runs/run-K1eShLCh/`: **230 tests / 6306 assertions**, all **46 PHPUnit phases**, including authentication, sync/async events, database outage/recreation and recovery. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-zCUNToYg/`; embedded **230 tests / 6308 assertions**, all **46 phases**, at `application/var/test-runs/run-f78VOUau/`. Five `authorizing.log` checkpoints retain all four assignment UUIDs/rows and eight decisions while proving anonymous-denied/native-session-authorized Task HTTP. |

The full check preceded four final **test-fixture-only** changes in persistence/event
E2E and `TaskBrowser`. Their targeted PHPStan and Symfony style commands passed:

```sh
./bin/dev composer exec -- phpstan analyse --no-progress tests/E2E/PersistenceCreateTest.php tests/E2E/PersistenceReadTest.php tests/E2E/EventsTest.php tests/Fixtures/Authorization/TaskBrowser.php
./bin/dev composer exec -- php-cs-fixer fix --dry-run --diff --sequential --config=.php-cs-fixer.dist.php tests/E2E/PersistenceCreateTest.php tests/E2E/PersistenceReadTest.php tests/E2E/EventsTest.php tests/Fixtures/Authorization/TaskBrowser.php
```

Final standalone and consumer E2E cover those files. Production code is unchanged
since the passing check. Two assertions differ between E2E runs because existing
asynchronous/concurrency polling is timing-dependent. Final Docker inspection found
development app/database healthy, no project worker or consumer/test containers.

### Observable behavior

- Enforcement E2E: **8 tests / 326 assertions**, plus outage preparation **1 / 13**,
  outage **1 / 30**, recovery **1 / 36**. Actual native form login and HTTP exercise
  missing grants, exact-resource/global grants, other accounts/resources, immediate
  revocation without reauthentication, and fixed no-store denials.
- Direct buses distinguish administrator actor from grant recipient; users cannot
  self-grant or inspect protected foundation APIs. A bare CLI bus and stale console
  token deny, while explicit operator adapters succeed.
- Caught nested command/query denials roll back an ORM-scheduled Task and immediate
  assignment SQL. An independent connection observes no early commit. Same-process
  subsequent operations recover, and rolled-back permission removal is restored.
- A successful direct Get uses **exactly three business reads**: owning account
  existence, permission evaluation and Task lookup, with zero writes. This excludes
  the separate native HTTP credential lookup. N=100 Authorizing budgets and populated
  plans remain covered by the full suite.
- Runtime unit tests (**53 / 429**) cover policy construction/invocation/nested-query
  write rejection, actor trust/lifecycle, and caught support failures preventing root
  command/query admission. Compilation/source/DI negatives cover missing declarations,
  invalid signatures, policy/handler proxies, locator reuse and authority-facade leaks.
- Consumer setup/recreation preserves assignments and a real session's authorized
  Task read; isolated tests do not mutate the consumer development data or keys.

### Resolved intermediate failures

The first check (`run-hjP5axgf`) passed its tests but failed two test PHPStan errors;
both are covered by the final passing check. Early architecture invocation without
the isolated bootstrap lacked `KERNEL_CLASS`; actual full check supplies it.
Initial container wiring required explicit trust-resolver binding and exclusions for
explicitly configured services. E2E `run-3N4ZheQL` exposed an oversized fixture email
local part and PHPUnit 13's separate group-option requirement. `run-ctrTfxRl` then
exposed the older persistence fixture's missing authority; persistence/event fixtures
now use explicit scopes or real session/grant setup. These failed attempts are not
final evidence. No production authorization bypass was added to fix fixtures.

## Original fresh independent reviews — 2026-09-15

- Runtime/security reviewer **`ses_f59626304ffe7FfAL8d0URx8mX`: APPROVED**, no concrete
  findings. Inspected all policies, trusted actor origins, nested scope/write/failure
  behavior, adapters and real verification evidence.
- Architecture reviewer **`ses_f596262c7ffelHpvG7S2VBGlix`: APPROVED**, no concrete
  findings or material missing coverage within the supported source/DI guardrails.
  Inspected declaration/signature/map enforcement, private locator exceptions,
  source/Deptrac alignment, authority facades and matching negatives.

Both reviewers inspected the completed consumer evidence after its passing run and
reaffirmed approval. Neither reran suites or edited files. Only documentation changed
after their final reviews. These reviews preceded the enum correction and additional
review recorded above. Task 9 is now user accepted; later subtask implementation and
Git commits/pushes require their own authorization.
