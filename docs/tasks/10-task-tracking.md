# Task 10 — TaskTracking use cases and CLI

## Superseding correction — updated 2026-09-22

**This implementation is NOT USER ACCEPTED.** The user rejected Authorizing's knowledge
of TaskTracking and approved the [generic Authorizing/native voter rework](10-authorizing-rework.md)
after pattern research, separate design and gap analysis. That record now owns the
active design and approved contract changes. The later verified intermediate reworks are
also superseded by the approved fresh-template four-table model. Its implementation,
verification and fresh independent review are complete; user acceptance is pending.
The original design and passing evidence below describe the installed pre-rework
baseline; they do not verify the correction. Preserve intended uncommitted work.

## Approval and status

On 2026-09-16, after discovery from the accepted Task 9 checkpoint (`270ba43`),
the user selected **revocable owner access**, **all viewable tasks**, and
**allow unowned tasks**. After the complete design, security/performance review and
acceptance criteria were presented, the user said exactly **“proceed”**.
This approves Task 10 implementation only; Git delivery and Task 11 need separate
authorization. Pre-correction status was **IMPLEMENTED, VERIFIED, REVIEWED — AWAITING
USER ACCEPTANCE**; the subsequent rework approval above supersedes that next gate.

Fresh design reviewer `ses_f561c8ec9ffediN9OgquqTMWi5` approved the revised design
after its event-caller isolation, exact account-existence callers and listing-budget
findings were incorporated. This is design review, not implementation verification.

## Approved design

- Task owns nullable immutable `ownerAccountId` and nullable `completedAt` (null means
  open). Migration `Version20260916010000` retains existing tasks as unowned/open.
  Opaque account UUIDs introduce no cross-module SQL, FK or ORM association.
- `CreateTaskCommand(title, ownerAccountId = null)` admits an account only with global
  create permission and self ownership; explicit `tasks` operators can select an
  existing owner or create unowned. The title-only HTTP adapter derives the owner
  target from native identity; the policy independently checks it.
- Owned creation explicitly nests `GrantInitialTaskAccessCommand(accountId, taskId)`
  before publishing the existing UUID-only creation event. Authorizing grants the fixed
  resource `task_tracking.editor` role, validates the catalogue/account and uses its
  existing per-account lock/write protocol. The policy requires the exact direct
  Create caller and self recipient or `tasks` operator. Ownership is descriptive;
  revocation removes access without clearing ownership or automatically regranting it.
- Event middleware brackets native delivery with an infrastructure-owned event caller
  frame. Listeners cannot borrow Create's bootstrap authority. Actor identity remains
  fixed; sync listener commands share the producer transaction, async listener commands
  retain independent roots/reset. Event success/failure must unwind the frame.
- `ListTasksQuery(visibilityAccountId = null, limit = 50, after = null)` admits account
  self visibility or `tasks` operator. Null target is operator-wide. Authorizing's exact
  caller-restricted `ListAccessibleResourcesQuery` computes task-view access using its
  own catalogue/tables plus Authenticating's account-existence API. It returns global
  access or distinct accessible resource UUIDs; TaskTracking loads its own bounded rows.
  Both new Authorizing handlers have exact account-existence caller permissions.
- UUID keyset pages are 1–100/default 50; all states included. Canonical cursors are at
  most 512 characters and bound to the visibility target. Resource candidate pages
  deduplicate before limiting. Orphans can produce short/empty pages with continuation;
  advance past the last selected candidate, never the lookahead or last hydrated row.
  No refill loops, totals, offsets or cross-page snapshot. Each page recalculates access.
- `CompleteTaskCommand(id)` requires exact-resource complete permission or `tasks`
  operator. It returns a nullable result with ID, changed flag and first completion
  timestamp. Domain completion is idempotent. UTC whole seconds match native Doctrine
  persistence precision. A locking repository operation preserves pending insertions
  and nested changes, refreshes clean stale state safely, and rejects dirty/stale
  conflicts rather than silently overwriting them. Only the root bus flushes/commits.
- CLI adds optional `create --owner`, `list [--visible-to] [--limit] [--after]`, and
  `complete`. Show/list include owner/completion fields; create retains UUID output.
  Existing creation events retain their wire shape. Completion activity belongs to
  Task 11; Twig and business API adapters retain their later gates.

## Security and performance review

Admission remains policy-only; permission calculation belongs to Authorizing and task
state invariants to TaskTracking. A target UUID/cursor is not actor authority. General
assignment management remains protected; bootstrap accepts no selectable role/key/scope.
The bootstrap trusts the reviewed Create handler to supply its freshly generated Task
UUID: caller identity is not independent proof of resource freshness. Platform source,
DI and compiler checks must keep the event caller frame on the shared execution context.

Global/resource permissions remain additive and live. Same-transaction checks do not
serialize against revocation; already-authorized work can finish. Account existence is
a snapshot, with retained orphan UUIDs possible. Unflushed account registration followed
by assignment remains unsupported. Task completion locks serialize its state transition,
not permission revocation. Conflicts propagate without automatic retries.

Operator-wide listing has a budget of at most **one business SQL read**. Every
account-targeted list, including operator `--visible-to`, has a budget of at most
**four reads**: account existence, global evaluation, optional resource candidates,
Task retrieval. Existing natural-key indexes support exact-key ordered resource scans;
populated PostgreSQL plans must prove exercised access paths. These are query/row bounds,
not universal latency guarantees. Authentication reads are separate.

## Acceptance and verification plan

1. Actual account/CLI create, Get, List and Complete journeys, forged owner/visibility
   denial, root/unrelated/listener bootstrap denial and management-policy preservation.
2. Initial grant/task/event atomicity in both transports, including caught failures;
   independent connection proves no early visibility. Event-context restoration covers
   synchronous redispatch and independently reset async listener commands.
3. Revoked owner access, additive grants, orphan resources/accounts including global
   sources, deduplication, empty-page continuation, bound cursors and live mode changes.
4. Real concurrent completion commit/rollback, first timestamp retention, nested pending
   create/complete/repeat, stale clean refresh, dirty stale rejection, outer rollback,
   database outage and recovery.
5. Populated legacy migration and preservation; N=100 SQL budgets and populated plans.
6. `./bin/dev setup`, `./bin/dev check`, `./bin/dev test`, and
   `TMPDIR=/tmp/opencode ./bin/dev verify-setup`; record exact results below. Fresh
   consumer checkpoints preserve owned Task/editor assignment UUID/completion while
   isolated tests run alongside development data.
7. Fresh independent implementation reviews after verification; resolve findings and
   reverify affected behavior before requesting user acceptance.

## Implementation and verification evidence

### Completed verification — 2026-09-16

| Exact command | Final result and evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**. First setup applied `Version20260916010000`; final setup retained existing RSA3072 keys, dependencies and current migration, with app/database healthy. Composer/Flex manifests and locks unchanged. |
| `./bin/dev check` | **PASS**, `var/test-runs/run-Jzd2z00j/`: **1467 tests / 8917 assertions** (architecture **855 / 4739**, unit **612 / 4178**); Deptrac **2200 allowed / 0 violations / 0 uncovered**. Audit, source/DI/collection metadata, PHPStan, Symfony style, syntax, shell/key contracts and both-mode fixture boots passed. |
| `./bin/dev test` | **PASS**, `var/test-runs/run-kLIovsdQ/`: **252 tests / 7346 assertions**, all **53 PHPUnit phases**, actual HTTP/CLI/PostgreSQL, both event transports, outages/recovery and cleanup. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-zfkkNeft/`; embedded **252 tests / 7350 assertions**, all **53 phases**, at `application/var/test-runs/run-4SWsQ0go/`. Five `task-tracking.log` checkpoints preserve owned Task/owner/editor assignment UUID/first completion timestamp and isolated visibility alongside the unowned marker. Existing authorization/session/JWT checks and two-consumer isolation passed. |

The four-assertion standalone/consumer difference comes from timing-dependent polling.
All final runs cover the explicit mapped column-name correction; no implementation
changes followed these runs or independent review. Only documentation changed afterward.
Final `docker ps --format '{{.Names}} {{.Status}}'` showed this project's app/database
healthy and no project test, worker or consumer containers remaining.

### Observable behavior and performance

- Task journeys **6 tests / 345 assertions**: real CLI owned/unowned create/show/list/
  complete, create-only account bootstrap, forged owner/visibility denial, management
  protection, revocation with retained owner metadata, additive sources and sparse
  resource/global pagination.
- Completion **10 / 398** in standalone: actual PostgreSQL subprocess lock barriers
  prove precommit invisibility, first-writer timestamp preservation, waiting writer
  success after rollback, pending insert/repeat, clean stale refresh, dirty stale and
  removed/deleted conflicts, nested rollback and same-process recovery.
- Atomicity **1 / 41** sync and **1 / 42** async: a final flush failure rolls back Task,
  initial grant and native queue row together. Compiled listener attack **1 / 24**:
  listener observes the granted Task, catches forbidden bootstrap, yet root still rolls
  back; successful nested completion and subsequent context restoration also pass.
- Task CLI outage **1 / 48**, plus expanded existing account-bus authorization outage/
  recovery phases, fail closed and recover against the actual restarted database.
- Populated migration verification retains existing UUID/title rows as unowned/open;
  repeated migration preserves explicitly owned/completed rows.

N=100 listing verification (**1 / 88**) used **8,000 unrelated assignments** and
**8,000 Task distractors**, without planner overrides:

| Journey | Read budget / observed populated plan |
| --- | --- |
| Resource-visible account page | At most **4 business reads**, no writes; 100 tasks returned. Candidate plan returns 101 IDs with lookahead, using both resource natural-key indexes. |
| Task hydration | One owning query, **100 rows**, `task_tracking_task_pkey`. |
| Resource continuation | At most **4 reads**, **1 remaining candidate**, existing resource natural-key indexes. |
| Global-view account page | At most **4 reads**, no resource-candidate query. |
| Actual operator-wide CLI list | Exactly **1 business read**, **101 rows** including lookahead, `task_tracking_task_pkey`. |

Plan observations do not establish universal latency/access-path guarantees. Public
results remain at most 100 items; lookahead stays internal.

### Resolved intermediate verification failures

- First check `run-6ZmbuscY` passed tests but caught an unreachable test assertion in
  `AuthorizationRuntimeTest`; removed the redundant assertion. The final full check
  covers it. A subsequent pre-column-correction check `run-3ef1yJee` passed but is not
  final evidence.
- First E2E `run-YO5ofsdL` caught Doctrine's default field naming differing from the
  migration's snake-case columns. Explicit `completed_at` and `owner_account_id` mapping
  names fixed the mismatch without modifying the applied migration. Final E2E/check/
  setup/consumer runs cover the correction.

## Fresh independent implementation reviews — 2026-09-16

Both reviewers independently inspected changed/new code and completed evidence and
**APPROVED with no concrete findings or material coverage gaps**:

- Security/policies/event caller boundary:
  **`ses_f55a7cd3effeWy1iCZ773BwaKm`**. Reviewed self ownership, fixed bootstrap,
  exact list/existence callers, shared event context/compiler negatives, CLI authority,
  collection metadata and actual compiled-listener caught-denial rollback.
- Persistence/list/concurrency/consumer evidence:
  **`ses_f55a7cd2effeL2gpYyxGtTaSdL`**. Reviewed migration and module SQL ownership,
  locked snapshot/dirty-state behavior, real concurrency, candidate deduplication/
  continuation, N=100 budgets/plans and all five fresh-consumer checkpoints. Confirmed
  consumer `src/`, `tests/` and `docker/tools/` match the reviewed working tree.

Neither reviewer edited files or reran suites. The subsequent user-requested rework
supersedes the former acceptance gate; these reviews remain baseline evidence only.
Task 11 discovery/design and Git delivery await their respective authorization.
