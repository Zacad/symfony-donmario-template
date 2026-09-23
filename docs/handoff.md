# Session handoff - Clean Authorizing acceptance pending

Date: **2026-09-22**

Repository: `/var/home/adam/Projects/symfony-donmario-template`

## Status and next gate

**The clean [Authorizing/native-voter/Task-ownership model](tasks/10-authorizing-rework.md)
is approved, implemented, fully verified and freshly reviewed; user acceptance is pending.** Do not
begin Task 11, commit or push. Main owns the active record.

This is a fresh-template-only redesign. Compatibility with authorization schemas or rows
from the unaccepted intermediate Task 10 reworks is explicitly not required. Rewrite
`Version20260915010000` as the complete clean baseline and delete
`Version20260917010000` and `Version20260920020000`. Existing intermediate databases must
be disposed/recreated; do not add compatibility migrations, fallback reads or cleanup APIs.

The clean Authorizing schema has exactly four tables:

| Table | Natural identity |
| --- | --- |
| `authorizing_role` | `role_key` |
| `authorizing_role_permission` | `(role_key, permission_key)` |
| `authorizing_role_assignment` | `(subject_id, role_key)` |
| `authorizing_permission_grant` | `(subject_id, permission_key)` |

There are no resource grants/assignments, initial binding, scope/resource columns,
`account_id` columns or synthetic assignment IDs. The approved global permission,
role/direct-grant, native-voter, Task ownership, DBAL repository, lock namespace and query
budget semantics otherwise remain.

Authorizing Domain remains organized by Assignment, Capability and Role. Explicit
technical suffixes are local to this module and classify responsibilities without creating
technical namespaces. Group by business reason to change; do not create `Domain/Mapping`,
`Domain/Entity`, `Domain/ValueObject` or empty placeholders. `Entity` includes rich and
narrow persisted models, `ValueObject` is immutable validated identity-free data, `Enum`
is closed backed vocabulary, `Service` is a stateless Domain collaborator, and persistence
ports retain `Repository`. Cross-concept validators/exceptions may remain at the root; do
not generalize this naming convention to another module without a separate decision.
`RoleEntity` owns creation,
definition/revision and retirement. Assignment uses
`AssignmentReferenceValueObject(kind, key)`,
`AssignmentChangeValueObject(operation, reference)` and
`AssignmentChangeCountsValueObject(added, removed)`; there is no `ChangeSet`,
`StoredAssignment` or Domain cursor. Public mutation fields are exactly
`operation`/`kind`/`key`; assignment rows/cursors use `kind`/`key`, with cursors also bound
to the subject.

| Clean-model stage | State |
| --- | --- |
| User design approval | **COMPLETE**, 2026-09-22 |
| Implementation | **COMPLETE** |
| Focused verification | **PASS: 723 / 4280** |
| Fresh setup/full check/E2E/fresh consumer | **COMPLETE** |
| Fresh independent review | **COMPLETE**: both reviewers APPROVED after recheck |
| User acceptance | **PENDING** |

Current evidence: check `run-K22OUIXu` passed **1147 / 6248**, Deptrac
**2285 / 0 / 0**; standalone E2E `run-MqAHjmgS` passed **246 / 6631**, all **53
phases**; fresh consumer `/tmp/donmario-setup-T6lsRfg6/` passed with embedded
`run-LWKUVPhh` at **246 / 6631**, all **53 phases**, plus setup, clean-schema,
defaults, persistence and isolation checkpoints. The repository-local development database
still contains the two intentionally unsupported intermediate migration records, so direct
setup refused it without data destruction; fresh and repeat setup passed in the consumer.

Initial reviewers `ses_f35d3a93dffeUpQzpNFkQkqQrG` and
`ses_f35d3a923ffeRNh6GdBk2tcT3v` found two runtime/coverage and four documentation/compiler-
coverage issues. They are resolved: retirement is one atomic entity transition, corrupt
retired revision-one rows fail closed, N=100 natural-key mutation/list continuation has a
populated PostgreSQL index plan, permission enum negative compilation cases are covered and
current documentation is reconciled. Focused recheck passed **128 / 288**; the full evidence
above is post-fix. Both reviewers rechecked the final tree without rerunning suites and
**APPROVED with no findings or material coverage gaps**. Runtime/security review:
`ses_f35d3a93dffeUpQzpNFkQkqQrG`; architecture/evidence review:
`ses_f35d3a923ffeRNh6GdBk2tcT3v`.

The user's final organization follow-up removed the empty physical `Domain/Mapping`
directory and made the concept-first and Authorizing-local suffix decisions explicit in
the normative architecture, engineer guide and agent instructions. Historical Mapping
references remain only in superseded provenance. Focused documentation reviewer
`ses_f32b82d86ffehclt4pcxs7aept` **APPROVED with no findings**; `git diff --check` passed
and no behavioral suite was rerun for this docs/empty-directory-only change.

### Next-session prompt

> Read `AGENTS.md`, this handoff, active `docs/tasks/10-authorizing-rework.md`, `README.md`,
> `docs/authorization.md`, `docs/architecture.md` and `docs/roadmap.md`; inspect manifests,
> locks and the working-tree diff. Continue only the approved clean Authorizing model.
> Preserve historical evidence, accepted 8b/9 delivery and intended uncommitted Task 10
> work. Do not add persisted-data compatibility. Inspect completed verification evidence
> and obtain fresh independent review, resolving and reverifying any findings.
> No Task 11 work or Git commit/push is authorized.

## Historical Intermediate Rework Status - Superseded

The following 2026-09-20/21 status and evidence accurately records the verified but
unaccepted intermediate model. It is regression provenance only and does not verify the
clean four-table baseline or preserve its schema requirements.

**The [global Authorizing/native-voter/Task-ownership redesign](tasks/10-authorizing-rework.md)
and its explicit public-action amendment were each user-approved with exact `proceed` on
2026-09-20.** The pre-amendment redesign was implemented, fully verified and freshly
reviewed. The amendment is implemented, fully verified and freshly reviewed; both
amendment reviewers approve with no findings. User acceptance remains pending. Do not
begin Task 11, commit or push. Main owns the task record.

The user approved the post-rework dead-code/fixture/test/documentation cleanup with exact
`proceed` on 2026-09-21. It is implemented, fully verified and freshly reviewed with no
findings. After raising Domain discoverability and cleanup-completeness concerns, the user
selected **“Bounded full cleanup (Recommended)”** on 2026-09-21. That follow-up is also
implemented, fully verified and freshly reviewed with no findings; user acceptance remains
pending. The user then approved business-concept Domain namespaces. Assignment, capability
and role types are now grouped by concept, while schema-only classes remain in
`Domain/Mapping`; that namespace-only follow-up is fully verified and freshly reviewed
with no remaining findings. None of these cleanups reopens the authorization model or adds
adapters/features.

### Historical implemented target in brief

- Every command/query handler has exactly one `#[Authorize]`. Restricted forms name a
  concrete final same-module voter; actor-unrestricted forms use only
  `#[Authorize(public: true)]`. Module-owned backed permission enums and stable labels
  feed `CqrsPass`, which rejects bare/mixed forms, builds the global `AuthorizationCatalog`
  and validates exact voter routing. Public actions add no capability. There is
  no authorization YAML or resource partition.
- One private lazy native voter per module is evaluated by a dedicated private native
  decision manager over only `app.authorization.voter`, using
  `UnanimousStrategy(false)`. It is isolated from firewall/global authorization and
  tracing. The explicit credential-free token carries Actor and support-read provenance;
  invocation/transaction ownership and event frames remain infrastructure context.
- The exact compiler-selected Platform public voter is private/lazy, recognizes only
  inventoried public messages, accepts only the internal token, has no dependencies/SQL
  and is untagged when unused. Public bus admission grants every actor but does not expose
  or bypass an HTTP/transport route.
- Authorizing has no Authenticating/Task imports or account lookup. Its subject-named
  management/evaluation and bounded runtime role/capability APIs operate on opaque UUIDs.
  Roles and direct grants are global and additive; explicit roles may combine installed
  permissions across modules. There is no wildcard or separate per-role permission max;
  the total 4096 active membership-edge bound remains. Batch JSON/cursors use `subjectId`;
  physical `account_id` storage, assignment UUIDs and advisory-lock namespace remain.
- Runtime PostgreSQL roles have immutable keys, revisioned label/bundle updates and
  irreversible retirement. Live evaluation joins active definitions/memberships; retired
  assignments list/remove but grant nothing. Defaults are explicit global snapshots:
  `task_tracking.user` has the three Task permissions, `authorizing.administrator` the
  two Authorizing permissions and `application.administrator` all five current
  permissions. Future capabilities are not added automatically.
- Resource assignment/grant rows grant nothing and cannot be added; they remain
  listable/removable for legacy cleanup. Retained role scope/resource columns and
  `authorizing_initial_resource_role` are inert. Generic resource and initial-binding
  APIs are removed; the authorization schema and `Version20260917010000` remain.
  Conditional migration `Version20260920020000` preserves historical global Task role
  assignments without creating those roles on fresh installations.
- TaskTracking owns account eligibility and immutable-owner admission. Accounts need the
  global permission plus exact ownership for Get/Complete and self owner filtering for
  List. Missing, foreign and unowned Tasks deny accounts; `tasks` operators bypass.
  Create requires persisted self plus global create; it performs no automatic grant.
- Task List uses `--owner`, pages 1-100/default 50 and the new
  `Version20260920010000` `(owner_account_id, id)` index. Expected business reads are raw
  entitlement 1, Create 2, Get 3, List 3, Complete 4 and operator List 1.
- `application.administrator` is an explicit permission snapshot, not a bypass. Business
  voters still enforce actor kind, account existence, ownership and contextual rules.
- Input/transaction/admission/result/event ordering, caught-failure poisoning, event-frame
  cleanup, Task completion locking, list pagination and revocation-race limits
  remain. Authorization/account/Task reads are snapshots during races.

### Historical intermediate evidence

| Stage | Result |
| --- | --- |
| Compiler-focused | **PASS: 499 / 3658** |
| Authorizing-focused | **PASS: 41 / 154** |
| Task-focused | **PASS: 36 / 127** |
| Scoped static/style/syntax | **PASS** |
| Integration/setup | **PASS** through `Version20260920020000`; app/database healthy |
| Full check | **PASS**, `run-bugsGQQV`: **1102 / 6082**, Deptrac **2326 / 0 / 0** |
| Standalone E2E | **PASS**, `run-0dhcVcQ5`: **243 / 6551**, all **53 phases** |
| Fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-5WO4i6pr/`; embedded `run-X2HZiZKb`: **243 / 6549**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Authorization/security review | **APPROVED with no findings**, `ses_f40e6a137ffexGfGutmbAoM5lt` |
| Persistence/performance/setup review | **APPROVED with no findings**, `ses_f40e6a10fffe3u30iD5Iya0dxa` |
| Public-action amendment check | **PASS**, `run-YdFZCQO0`: **1117 / 6160**, Deptrac **2338 / 0 / 0** |
| Public-action amendment E2E | **PASS**, `run-mcbPhvEu`: **243 / 6551**, all **53 phases** |
| Public-action amendment fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-GH880Uk2/`; embedded `run-7e3fyExj`: **243 / 6549**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Public-action amendment security/architecture review | **APPROVED with no findings**, `ses_f3faeadd9ffekQfHYhpA16htvB` |
| Public-action amendment implementation/runtime/test review | **APPROVED with no findings**, `ses_f3faead01ffe3ztZxIfDdppVk6`; stale Collections README wording corrected |
| Post-rework cleanup approval | **COMPLETE**: exact `proceed`, 2026-09-21 |
| Post-rework cleanup implementation/focused verification | **COMPLETE** |
| Post-rework cleanup focused PHPUnit selection | **PASS: 510 / 3917** across affected authorization/runtime/unit/architecture suites |
| Post-rework cleanup check | **PASS**, `run-1wjNygkA`: **1118 / 6168**, Deptrac **2321 / 0 / 0** |
| Post-rework cleanup standalone E2E | **PASS**, `run-jKMu34tA`: **244 / 6547**, all **53 phases** |
| Post-rework cleanup fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-GnWbKpP7/`; embedded `run-haQZJCKf`: **244 / 6547**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Post-rework cleanup security/runtime review | **APPROVED with no findings**, `ses_f3c575379ffee3DS6e7LpzI86H`; no suites rerun |
| Post-rework cleanup persistence/tests/docs review | **APPROVED with no findings**, `ses_f3c57534fffe0ulEUkve8TXPLI`; no suites rerun |
| Domain organization follow-up approval | **COMPLETE**: user selected **“Bounded full cleanup (Recommended)”**, 2026-09-21 |
| Domain organization follow-up final check | **PASS**, `run-kydGbTrF`: **1119 / 6173**, Deptrac **2319 / 0 / 0** |
| Domain organization follow-up standalone E2E | **PASS**, `run-lRiRb342`: **244 / 6545**, all **53 phases** |
| Domain organization follow-up fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-6Cyl5Sod/`; embedded `run-6Cflj2DF`: **244 / 6541**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Domain organization implementation review | **APPROVED with no findings**, `ses_f3ac53f4effej7rD4xiCuPuzyC`; no suites rerun |
| Domain organization tests/docs review | **APPROVED after four findings were resolved**, `ses_f3ac53e51ffegJQaa52DELgFvh`; final recheck has no findings, no suites rerun by reviewer |
| Concept namespace focused verification | **PASS: 418 / 3500** across Domain/application/repository/compiler/source suites |
| Concept namespace final check | **PASS**, `run-P3J7V3eL`: **1119 / 6173**, Deptrac **2319 / 0 / 0** |
| Concept namespace standalone E2E | **PASS**, `run-gmpy3e9m`: **244 / 6538**, all **53 phases** |
| Concept namespace fresh consumer | **PASS**, `/tmp/donmario-setup-YLUpCxuq/`; embedded `run-GEsnErrk`: **244 / 6547**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Concept namespace implementation review | **APPROVED after the empty experimental directory was removed**, `ses_f3a72b8d2ffev4JHDkDdRoGAlQ`; final recheck has no findings, no suites rerun |
| Concept namespace docs/coverage review | **APPROVED after the same finding was resolved**, `ses_f3a72b8c2ffeQvI5DX3fdNGLwf`; final recheck has no findings, no suites rerun |

The public-action amendment, pre-amendment redesign and all cleanup follow-ups were fully
verified and freshly reviewed for that intermediate model. The clean-model approval
supersedes its former user-acceptance gate.

### Historical next-session prompt - superseded

> Read `AGENTS.md`, `docs/handoff.md`, active `docs/tasks/10-authorizing-rework.md`,
> `README.md`, `docs/architecture.md`, and `docs/roadmap.md`. Inspect `composer.json`,
> `composer.lock`, `symfony.lock` and the working-tree diff. The 2026-09-20 global
> permission/Task-ownership redesign was approved with exact `proceed` and is implemented
> fully verified and freshly independently reviewed with no final findings. The bounded
> post-rework cleanup was separately approved with exact `proceed`; the subsequent bounded
> Domain cleanup and business-concept namespace organization were selected by the user.
> All are implemented, fully verified and freshly reviewed with no remaining findings.
> Obtain user acceptance before Task 11. Preserve intended uncommitted Task
> 10 changes based on `270ba43`, historical evidence, local secrets/volumes and applied
> migrations. No Task 11 work or Git commit/push is authorized.

## Pre-rework Task 10 regression baseline — not user accepted

**Task 10 — TaskTracking use cases/CLI was IMPLEMENTED, VERIFIED and REVIEWED on
2026-09-16; the authorization design is now superseded by the approved correction.**
The user originally selected revocable owner access,
all viewable tasks and unowned legacy/operator tasks, then approved the complete
design with **“proceed”**. See [Task 10](tasks/10-task-tracking.md) for the approved
design, implementation boundaries, exact commands/evidence and fresh reviews.

| Command | Final result |
| --- | --- |
| `./bin/dev setup` | **PASS**; first applied `Version20260916010000`, final retained keys/dependencies/current migration; app/database healthy. |
| `./bin/dev check` | **PASS**, `var/test-runs/run-Jzd2z00j/`: **1467 tests / 8917 assertions**, Deptrac **2200 allowed / 0 violations / 0 uncovered**. |
| `./bin/dev test` | **PASS**, `var/test-runs/run-kLIovsdQ/`: **252 tests / 7346 assertions**, all **53 PHPUnit phases**. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-zfkkNeft/`; embedded **252 / 7350**, all **53 phases**, at `application/var/test-runs/run-4SWsQ0go/`; all five TaskTracking ownership/grant UUID/visibility/completion persistence checkpoints passed. |

Fresh independent reviewers **`ses_f55a7cd3effeWy1iCZ773BwaKm`** (security/policies/
event caller boundary) and **`ses_f55a7cd2effeL2gpYyxGtTaSdL`** (persistence/list/
concurrency/consumer) **APPROVED with no findings or material coverage gaps**. They
inspected completed evidence, did not edit or rerun suites. Only docs changed afterward.
N=100/8k populated plans and actual concurrent completion/rollback passed. Final
Docker inspection found this project's app/database healthy, with no test/worker/
consumer containers. Dependencies/locks are unchanged.

The former next gate of accepting this implementation is superseded by the approved
rework above. Task 11 remains deferred. Do not rerun passing suites merely to resume.
Main owns the records. **No Task 10 Git delivery is authorized**;
the earlier request delivered accepted 8b/9 as `270ba43`, not these current changes.
Preserve the intended uncommitted Task 10 work. Earlier next-Task-10 discovery notes
below are superseded history.

### Installed pre-rework Task 10 boundaries

- Task carries nullable immutable owner UUID and nullable UTC-second completion time.
  Legacy rows remain unowned/open. Owned creation nests the fixed editor grant before
  the existing creation event, atomically. Policies require account self ownership;
  CLI may choose `--owner` or create unowned. Ownership grants no revocation bypass.
- Four Task CLI adapters use exact `tasks` scope. List optionally selects `--visible-to`;
  every account-targeted list uses live Authorizing visibility. UUID keyset pages are
  bounded to 100/default 50. Orphans can produce empty pages with continuation; no refill
  loops. Operator-wide costs one business read; targeted lists at most four.
- New Authorizing grant/list APIs have exact direct caller, actor/target and scope
  policies. Their account-existence reads have exact caller allowances. General
  management remains protected. Cross-module integration uses public bus DTOs only.
- Event middleware brackets native handling with a shared execution-context event
  frame. Listeners cannot borrow Create bootstrap authority; async commands retain
  independent transaction/reset semantics and no publisher identity is queued.
- Completion requires exact complete permission or Task operator, is idempotent and
  row-lock serialized. Preserve pending local changes; refresh clean stale state only;
  dirty/stale or removed/deleted managed conflicts fail with rollback. Root bus alone
  flushes/commits. Same-transaction authorization does not serialize revocation.

## Accepted Task 9 checkpoint (historical delivery)

**Latest accepted checkpoint: Task 9, including the `ActorKind` enum correction, is
IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-16.** After the additional
fresh independent design/implementation review, the user said exactly:

> ok, i accept task, nest task will be continued in fresh session

Fresh reviewer **`ses_f5691c623ffeKmeUgmFSPqCWQ7` APPROVED** the design and implementation
with no substantive findings or material coverage gaps after inspecting code and final
evidence. No suites rerun or code edits followed that review. The acceptance is recorded
in [Task 9](tasks/09-authorization-enforcement.md#user-acceptance--2026-09-16).

The former next gate was Task 10 discovery/design; that gate and implementation
approval are now satisfied as recorded above. **Historical Git delivery authorization:**
on 2026-09-16 the user said exactly
**“commit and push changes”**. This delivery commit includes the accepted 8b/9 work,
enum correction and handoff. Use Git history for its hash; future commits/pushes need
fresh authorization. Earlier uncommitted/no-authorization notes are historical.

**Completed correction (2026-09-16):** `Actor::$kind` is the exact unbacked `ActorKind`
enum (`Anonymous`, `Account`, `Operator`, `Authentication`) at the user's request.
Setup passed. Check `run-SSb9mtrr`: **1360 tests / 7959 assertions**, Deptrac
**1887 / 0 violations / 0 uncovered**. E2E `run-0BEEcpKm`: **230 / 6306**, all 46 phases.
Consumer `/tmp/opencode/donmario-setup-M4B96yAf/` passed with embedded **230 / 6310**
at `application/var/test-runs/run-uTaVmkbI/`, all 46 phases and five authorized/denied
HTTP checkpoints. Fresh reviewer `ses_f574cd1afffet0VIFWAYaHvPMl` **APPROVED** after
inspecting the correction and final consumer evidence, no findings or suite reruns.
Only docs changed after review. Task 9 including the correction is now user accepted;
the remaining original evidence below predates the enum. See the Task 9 record for
exact commands. Do not rerun passing suites merely to resume.

**Earlier accepted checkpoint (2026-09-15): [8b](tasks/08-authorizing.md) is IMPLEMENTED,
VERIFIED, REVIEWED and USER ACCEPTED.** Following the full policy-only enforcement
design, the user said **“accept and proceed”**; main recorded acceptance of 8b and
approval for [Task 9 implementation](tasks/09-authorization-enforcement.md).
Task 9 is now **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED**.
Main owns the [Task 9 record](tasks/09-authorization-enforcement.md) and final results.
The user's subsequent Git request authorizes delivery of this accepted 8b/9 work.

| Original Task 9 command (before enum correction) | Retained evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained keys, dependencies and current migration. |
| `./bin/dev check` | **PASS**, `var/test-runs/run-1nmJjuRE/`: **1335 tests / 7775 assertions** (architecture **819 / 4453**, unit **516 / 3322**), Deptrac **1878 / 0 violations / 0 uncovered**. |
| `./bin/dev test` | **PASS**, `var/test-runs/run-K1eShLCh/`: **230 tests / 6306 assertions**, all **46 PHPUnit phases**. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-zCUNToYg/`; embedded **230 tests / 6308 assertions**, all **46 phases**, `application/var/test-runs/run-f78VOUau/`. Five authorization persistence + actual session-authorized/anonymous-denied HTTP checkpoints. |

Fresh independent reviewers `ses_f59626304ffe7FfAL8d0URx8mX` (runtime/security) and
`ses_f596262c7ffelHpvG7S2VBGlix` (architecture) **APPROVED** with no findings, including
final consumer evidence inspection. Neither reran suites. The full check preceded
four E2E fixture-only changes with passing targeted PHPStan/style; final standalone
and consumer E2E cover them. No production changes followed the passing check, and
only docs changed after review. See the task record for exact commands and historical
failed attempts. App/database are healthy; no project worker/test/consumer containers
remain. Do not rerun passing suites merely to resume.

Earlier awaiting-8b-acceptance and Task 9 design-gate records below are **historical
and superseded by this checkpoint**. Retained 8a/8b results do not verify Task 9.
The former Task 10 discovery/design gate is superseded by the current status above.

### Accepted historical checkpoints

**Accepted [Subtask 8a — Application DTO collections](tasks/08-authorizing.md) is
IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15.** The user approved
“proceed”, followed by “continue”, for the split 8a/8b design. Final setup, check,
actual PostgreSQL E2E and fresh-consumer verification passed. Both fresh independent
implementation reviewers approved with no findings and did not run suites.
Earlier fixture YAML/static issues are resolved. Verification and reviews completed
on **2026-09-14**; exact 8a evidence is below.
**At the earlier 8b handoff it was IMPLEMENTED, VERIFIED, REVIEWED — AWAITING USER
ACCEPTANCE on 2026-09-15; this status is superseded by acceptance above.**
On **2026-09-15**, responding to the request to accept 8a and proceed to 8b, the user said exactly:

> commit, push and proceed

This accepts 8a, explicitly authorizes commit/push of the verified 8a checkpoint only,
and approves beginning 8b. Main committed and pushed 8a as **`367fdfe` to `origin/main`**
on 2026-09-15, then implemented 8b. Main owns task 8's record and the Git workflow.
8b remained uncommitted until the subsequent 2026-09-16 delivery authorization.
8a was then the latest accepted checkpoint. Final 8b verification and both fresh
independent reviews were complete; the subsequent acceptance and full Task 9 design
approval supersede that handoff's remaining gates.

**Accepted Subtask 6 — Authenticating web includes the user's approved 2026-09-13
correction: registration owns password-policy validation and hashing. Subtask 6
including the correction is VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-13.**
No new design gate is needed for this correction. Post-correction setup/check/test
and fresh-consumer verification passed. Check: **610 tests / 3942 assertions**;
E2E: **137 tests / 1991 assertions**; consumer embedded E2E: **137 tests / 1990 assertions**.
Fresh independent correction reviewer `ses_f64339484ffeQjNteclNnjAlvj` inspected
code/evidence and **APPROVED** with no concrete findings. Earlier two approvals cover
the unmodified native web-authentication scope.

**Accepted Subtask 7 — Authenticating JWT is IMPLEMENTED, VERIFIED, REVIEWED and
USER ACCEPTED on 2026-09-14.**
The user approved “i accept, proceed”, including the correction requiring `/api/me`
to use QueryBus → `GetAccountIdentityQuery` → handler → Domain safe-identity lookup →
`GetAccountIdentityResult`, a deliberate second indexed read after authentication.
Final setup, full check, actual HTTP/PostgreSQL E2E, fresh-consumer verification and
two fresh independent reviews passed on 2026-09-13. Exact Subtask 7 evidence appears
below and in its task record. On 2026-09-14 the user accepted with the exact message:

> i accept, we will work on next subtask in fresh session

The accepted JWT checkpoint has since been followed by the approved 8a/8b design
and completed 8a implementation, verification and fresh independent review on 2026-09-14,
then user acceptance and approval to begin 8b on 2026-09-15.

Subtask 5b remains accepted: actual container/PostgreSQL verification and independent
reviews completed, and the user accepted it on 2026-09-13 with “i accept” and requested
commit/push for that historical checkpoint. Its native producer-transaction EventBus
semantics remain current.

Subtasks 1, 2, 3a, 3b and 4 were accepted. Historical task 4 postcommit delivery and
task 5 custom durable subscriptions are superseded by 5b. Their records are retained
as historical evidence, not current operating instructions.

## Read first

1. `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/roadmap.md`.
2. `docs/tasks/10-authorizing-rework.md` — main-owned active correction record;
   reconcile its progress with subsequent user/main updates.
   `docs/tasks/10-task-tracking.md` — pre-rework implementation and regression evidence.
   `docs/tasks/09-authorization-enforcement.md` — accepted enforcement checkpoint.
   `docs/tasks/08-authorizing.md` — accepted 8a/8b design and retained evidence.
   `docs/tasks/07-jwt-authentication.md` — accepted design, verification/review evidence
   and user acceptance recorded on 2026-09-14.
   `docs/tasks/06-web-authentication.md` — approved brief/correction, implementation,
   current verification status and pre-correction historical evidence/reviews;
   user acceptance recorded on 2026-09-13, including the registration correction.
   `docs/tasks/05b-native-event-bus.md` retains the accepted event checkpoint.
3. Composer/Flex manifests and locks; inspect current Git status before editing.

The working tree was **clean at the start of 8a implementation**, at existing commit
`c29a3f9`. The earlier uncommitted Subtask 6/7 handoff description is historical.
8a is accepted and pushed as `367fdfe`. Preserve the accepted 8b/9
implementation, tooling, tests and documentation edits. Inspect current changes
before editing. The user's 2026-09-15 commit/push request applied only to the verified
8a checkpoint; the subsequent 2026-09-16 request authorizes this 8b/9 delivery only.
Preserve the instruction to use subagents for independent work.

## Historical Task 9 policy baseline - superseded operating design

The identifiers and policy mechanism in this section describe the accepted Task 9
checkpoint only. They are intentionally retained as historical provenance and are not
current implementation instructions; the native-voter cutover at the top supersedes them.

- Handler-level `#[AuthorizeWith(...)]` selects a co-located private policy for each
  command/query. Source/DI/Deptrac rules align; policies cannot be injected into
  handlers. Only authorization middleware's private framework locator resolves them.
- Immutable `PolicyContext(actor, supportRead, caller)` comes from trusted execution
  context. Native fully authenticated HTTP supplies an account UUID, separate from
  message targets. Exact operator adapters establish `accounts`, `assignments` or
  `tasks`; existing Task create/show CLI has no actor argument. Native account-provider
  hash upgrades use only matching account-bound authentication scope. `/api/me` is self-only.
- Exact permission/existence foundation policies admit support reads and their stated
  operator/caller cases, with no general internal bypass. Other nested calls reauthorize.
  Policies may read through QueryBus and owning Domain ports/state, never dispatch
  commands/events. This is not an arbitrary PHP/SQL sandbox. Permission calculation,
  scope validation and password/hash validation stay in use-case logic.
- Input validation precedes policy admission; command authorization runs inside the
  owned transaction. Caught nested failures invalidate the root even if a policy
  returns true; actor changes inside bus execution fail. Queries gain no automatic
  transaction, and admission does not serialize against revocation.
- Dev/test Task HTTP routes use web-session identity, with fixed `Access denied.`
  JSON and 401/403 for anonymous/authenticated denial. Create requires global create;
  Get requires exact-resource view. No automatic Task grants, list filtering or
  durable service identity yet. The historical [Task 9 record](tasks/09-authorization-enforcement.md)
  preserves the exact policies, adapters and limits.

## Historical accepted 8b implementation - superseded API names

The account-named APIs and account-coupled behavior below are retained only as evidence
of the accepted 8b checkpoint. Current Authorizing APIs are subject-named and generic.

- Authorizing owns `Domain/AuthorizationCatalog` and four mapped global/resource
  role-assignment and direct-permission-grant tables. Module migration
  `Version20260915010000` was applied by passing setup; dependencies and locks are
  unchanged. The historical [8b record](tasks/08-authorizing.md) preserves exact
  entity/table names and ownership boundaries.
- Three public APIs are implemented: `ChangeAccountAssignmentsCommand`,
  `EvaluatePermissionsQuery` and `ListAccountAssignmentsQuery`. Authenticating owns
  `CheckAccountExistenceQuery`, exposing only UUID/existence results through QueryBus.
  Eight operator console commands adapt these APIs; authority comes from trusted
  deployment shell/container access, now expressed as explicit `assignments` scope.
  No new HTTP/API management routes exist. The historical 8b record preserves its
  original commands, batch JSON examples and catalogue customization.
- Sources are additive, with no cache or JWT/session permission authority. Global
  checks use only global sources for the capability across its declared resource
  type; resource checks also use exact `(type, UUID)` sources. Known wrong scope/type
  is invalid; missing accounts and unknown permission keys deny. Whole role bundles
  must fit an addition's scope. Retired keys/types remain listable/removable.
- Atomic 1–100-item changes take a per-account transaction advisory lock and use at
  most eight DML statements, with affected-row idempotency. Additions observe account
  existence **before** that lock; it is only a snapshot. The lock lasts until the
  root ends; handlers/repositories do not flush, commit or retry. Resource existence
  belongs to its owning module; omitting resource-existence SQL allows nested initial
  access for an unflushed owned Task. Unflushed account registration plus assignment is unsupported.
- Evaluation is bounded to two business SQL reads including account existence.
  Listing uses one bounded keyset query, page size 1–100/default 50, ordered by
  account/source/immutable assignment UUID, with no totals or cross-page snapshot.
  Console scope, stdin and cursor bounds are documented in README and architecture.

### Completed 8b verification and review — 2026-09-15

Main completed the following final 8b verification. **The then-current AWAITING USER
ACCEPTANCE status is superseded by 8b user acceptance above.**

| Command | Final result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; Authorizing migration `Version20260915010000` applied, existing keys/dependencies retained, app/database healthy. Dependencies/locks unchanged. |
| `./bin/dev check` | **PASS**: **1105 tests / 6276 assertions**, Deptrac **1679 allowed / 0 violations / 0 uncovered**. `var/test-runs/run-5YVJm6fG/`. |
| `./bin/dev test` | **PASS**: **219 tests / 5581 assertions**, all **42 PHPUnit phases**. Authorizing **15 tests / 848 assertions**, plus outage **1 / 8** and recovery **1 / 39**. `var/test-runs/run-mAZqm44t/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-taLhYutC/`; embedded **219 tests / 5581 assertions**, all **42 phases**, at `application/var/test-runs/run-chCqGwQF/`. Consumer `authorizing.log` records **five checkpoints**, retaining the same four assignment-row UUIDs and eight permission decisions across setup, recreation and E2E. |

Actual PostgreSQL performance verification passed with **N=100** and **8,000
unrelated rows** for populated EXPLAIN access paths:

| Journey | Observed SQL budget / populated plan |
| --- | --- |
| 100 permission decisions | Exactly **two business reads**: owning Authenticating existence plus Authorizing evaluation. Indexed plan returned **100 rows**, total planner cost **4229.93**. |
| 100 additions / mixed mutation batch | **One DML** for 100 additions in one group; **eight DML** for a mixed batch spanning all eight source/operation groups. |
| First assignment page, limit 7 | **One page SQL query**, **8 rows** including lookahead, all four account/cursor indexes, **five Limit nodes**, total planner cost **26.22**. |
| Source-3 continuation | **One page SQL query**, **8 rows** including lookahead; only source 3's cursor index, prior source ranks pruned, total planner cost **8.23**. |

Planner costs are estimates, not elapsed time; these observations do not promise
universal plans or latency. The account-existence snapshot, transaction ownership,
operator authority and cursor boundaries above remain unchanged.

Both **fresh independent implementation reviewers APPROVED with no findings**:

- Policy/CLI: `ses_f5beb07f6ffely21CI4G0EXW7N`.
- Persistence/evidence: `ses_f5beb0781ffe8vZ9yo2cnH90jq`.

Neither reviewer reran suites. **Only documentation changed between 8b review and
acceptance.** Main owns the detailed [task 8 record](tasks/08-authorizing.md) and the
separate current Docker-health inspection; the setup health result above is completed
verification evidence, not a claim that this subsequent inspection has run.

Historical first check: `var/test-runs/run-uwboQ1mD/` passed **1105 tests / 6276
assertions**, Deptrac **1679 / 0 / 0**, before the first E2E exposed cursor
Domain-exception classification and EXPLAIN numeric-assertion failures. Those fixes
are covered by the final passing runs above; the first attempt is not final evidence.

Historical next gate was 8b acceptance followed by Task 9 discovery/design and
implementation approval. Those gates are now satisfied; Task 9's verification/review
is complete and user acceptance followed on 2026-09-16. The subsequent explicit Git
request authorizes delivery of 8b/9. Accepted 8a evidence below is preserved.

## Current 8a implementation

- Exact `Application/<UseCase>/<Name>Input` joins Command/Query/Result public data
  classification in source/Deptrac, container exclusions and CQRS rules. Inputs are
  non-service nested data, never dispatched or returned as top-level handler results.
  Collections use native `array`, constructor `@param list<T>`, final readonly DTOs,
  public promoted typed properties and empty constructors; optional promoted `@var`
  must agree. Empty `[]` defaults and named DTO nesting are allowed.
- `tools/Architecture/CollectionDocTypes` resolves PHPDoc names/import aliases;
  `CollectionContracts` inventories typed homogeneous lists, doc-only dependencies
  and cascade/cycle requirements without executing application source. Non-null
  scalars, UUIDs, immutable dates, concrete CQRS/Input DTOs and backed data enums are
  supported. Maps, nullable items, item unions, untyped arrays, nested generic lists,
  aliases/templates and recursive collection-bearing graphs fail source policy.
- `CollectionValidationMetadata` and `CollectionValidationKernel` audit loaded
  native Default-group metadata through `php tools/collection-validation.php` in
  `./bin/dev check`. Require the supported exact sibling `Type(list)`, finite
  nonnegative integer `Count(max)`, `All` with explicit `NotNull` and matching item
  `Type`, plus property `Valid` for DTO list items and ordinary DTO edges leading
  to collections. Register mappings explicitly; arbitrary equivalent wrappers,
  class cascades or group-sequence overrides are not substitutes. README supplies
  the native YAML example and item field constraints.
- **phpstan/phpdoc-parser 2.3.5** is an explicit direct development dependency;
  no package versions were updated and runtime validation needs no PHPDoc parser.
- Native input validation runs before command transaction work.
  `src/Platform/Messaging/ResultValidationMiddleware.php` runs immediately before
  handling and validates returned DTOs on unwind inside invocation/transaction scope,
  before commit. Invalid output raises fixed internal
  `cqrs.result_validation: Handler returned invalid data.` without result/violation
  payloads. Caught nested command/query output failures invalidate the root.
  Collection outputs require Result envelopes. Compilation rejects collection-bearing
  Command/Query return types, including transitive DTO fields and union members;
  scalar/null/value/enum/void contracts
  retain their existing behavior. Events retain their collection-free/wire contracts.
- Trusted in-process code must construct ordinary owned lists: readonly is shallow,
  and array references/mutable subclass state are not universally prevented. Accepted
  item limits do not bound all allocation/traversal; native `Valid` may traverse
  despite other failures. Bound external bytes/items before construction and keep
  validation cheap/database-independent. This is not universal traversal or deep
  immutability proof; existing in-memory invocation/exception objects are not erased.

### Completed 8a verification and review — 2026-09-14

Main completed the following verification. The earlier fixture YAML failure and
static-analysis/style issues are fixed; these final results supersede failed attempts.

| Command | Final result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys, locked dependencies and current migration; app/database healthy. |
| `./bin/dev check` | **PASS**: **656 architecture tests / 3619 assertions + 166 unit tests / 1112 assertions = 822 tests / 4731 assertions**. Deptrac **1266 allowed / 0 violations / 0 uncovered**. `var/test-runs/run-vjLRhO18/`. |
| `./bin/dev test` | **PASS**: **202 tests / 4673 assertions**, all **39 PHPUnit phases**, including the collection PostgreSQL journey **1 test / 82 assertions**. `var/test-runs/run-JcI8AkkQ/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-0X5cqk2l/`; embedded **202 tests / 4676 assertions**, all **39 phases**, including collections **1 test / 82 assertions**, at `application/var/test-runs/run-pGssVhBz/`. |

Standalone E2E preceded the final **compiler-only** guard tightening: collection-bearing
Command/Query return types, including transitive DTO fields and union members, must
use Result envelopes. Runtime middleware was unchanged. The final full check and
subsequent consumer setup/full embedded E2E cover the final code; no repeat suite is
needed merely to resume. The three-assertion E2E difference comes from async polling.

Verification covers source/metadata positives and negatives, input rejection before
transaction work, actual PostgreSQL invalid-result and caught nested command/query
rollback, no precommit visibility through an independent connection, same-process
recovery, authentication/event regressions and consumer isolation.

Both **fresh independent implementation reviewers APPROVED with no findings**:

- Source/metadata: `ses_f5e99ad9affeSqojFsFsqYat0w`.
- Runtime: `ses_f5e99ad4cffe6CQx3YoBgJaVN8`.

They inspected implementation/evidence and **did not run suites**. These are
implementation reviews in addition to the earlier design review. Main owns the
[task record](tasks/08-authorizing.md). **8a was USER ACCEPTED and pushed as `367fdfe`
on 2026-09-15; 8b is now also user accepted.**

## Current web-authentication boundaries

- Native Symfony `form_login` and `UI/Http/Security/AccountUserProvider` read owning
  Domain credential snapshots directly for login and UUID refresh: the explicitly
  approved exception, with no `LoginCommand` or public credential query. Domain has
  no Security dependency. Registration dispatches plaintext
  `RegisterAccountCommand(email, password)`; its handler calls `EmailAddress::normalize`,
  `PasswordPolicy::validate`, then the Domain `PasswordHasher` port, constructs the
  Account and calls repository `add`. `SymfonyPasswordHasher` delegates only to the
  native hasher. The existing CommandBus transaction wraps registration validation,
  hashing and persistence. Native Symfony computes a replacement hash before dispatching
  `UpgradePasswordHashCommand(accountId, expectedPasswordHash, newPasswordHash)`.
  This separate command remains hash-only; its transaction uses conditional replacement
  (CAS) to prevent stale hash overwrites.
  Plaintext is not persisted, queued or logged, but the public readonly command,
  envelope and validation-exception objects can retain it in memory. Unsetting a CLI
  local is not guaranteed erasure. No public credential query/result/event exists.
- Exact internal non-service
  `Authenticating/Infrastructure/Framework/Symfony/Security/AccountPrincipal` permits
  Symfony's diagnostic placeholder only, not general `UserInterface` service exemptions
  or principal injection. Session serialization stores only the crc32c password-hash
  fingerprint, never the reusable hash/password.
- Email: ASCII, outer ASCII trim, lowercase, at most 254 bytes. Password: valid UTF-8,
  at least 15 Unicode characters, at most 4096 bytes, spaces preserved, no NUL/line
  breaks. Terminal provisioning requires hidden password/confirmation with no visible
  fallback; bounded explicit stdin mode is available. CLI responsibilities are secure
  input, confirmation, removal of one optional terminal LF/CRLF as transport framing,
  raw email/password dispatch and fixed errors, with no business validation or hasher.
  Passwords stay out of argv,
  environment, URL queries and logs; never dump sensitive payloads/exceptions.
- Native logout requires POST/CSRF. Invalid login CSRF preserves existing authentication;
  late password-migration failure explicitly clears the token and invalidates the
  session. Native files use `var/sessions/<env>`, cookie `dm_<PROJECT_ID>_<env>`:
  host-only, HttpOnly, SameSite=Lax, Secure=auto. Browser-session cookies/GC have no hard TTL.
- Native limiter: 5 failures/minute per normalized identifier/IP and 25/IP/5 minutes,
  dedicated `var/security/<env>` storage/locks and stable secret-based keys. Single-host
  native I/O is not guaranteed fail-closed; concurrent attempts can race. Empty flock
  files may be 0666 under 0700 directories; they accumulate. Never unlink active locks.
- Caddy enforces framed authentication POSTs at 16 KiB (oversized 413), unframed 411,
  external `/index.php` aliases 404 before internal rewrite. Preserve those guards.
  The web firewall excludes `/api`; Task 9 policy enforcement is verified/reviewed,
  user accepted on 2026-09-16.

Security/RateLimiter/Lock are locked at 8.1.6, PasswordHasher/CSRF at 8.1.0;
development BrowserKit/DomCrawler at 8.1.5 and CssSelector/Mime at 8.1.6. Mime is
needed for BrowserKit's real HTTP path. Only the `verify-setup` consumer PTY/cookie
helper additionally requires host Python 3; no host PHP/Composer is needed.

## Current JWT implementation

- Native POST `/api/login` accepts bounded JSON email/password; there is no success
  handler. The fully authenticated thin controller issues only after native password
  migration completes. GET `/api/me` uses the exact Application query path above and
  returns current UUID/email. GET `/api/docs.json` is public JSON OpenAPI, with an
  exact `security: false` firewall; even an invalid bearer header does not require
  authentication there. Authorizing model/management is user accepted; Task 9 policy
  enforcement is verified/reviewed and user accepted. Business API adapters
  retain their later gate.
- Login/bearer firewalls are stateless; API requests do not create/invalidate web
  sessions or use cookies as authentication. Reuse native providers/hasher/CAS and
  shared web/API limiter. Fixed errors/no-store, strict framed 16 KiB login JSON and
  header-only bearer extraction (8 KiB token plus prefix) retain the ingress bounds.
- RS256/RSA3072, TTL 900 and skew 0. Minimal sub/iss/aud/iat/nbf/exp claims require
  UUID, project/environment issuer/audience, nbf=iat and exactly exp-iat=900 after
  native signature/date normalization. No public credential DTO or password-derived
  claim. Bearer authentication performs a live UUID credential read; `/api/me` adds
  the Domain identity read through QueryBus. Deleted accounts are denied; deletion
  between reads is 404. Exact UI `AccountIdentityResource` is non-service transport
  data, not a generalized resource or direct-principal query-projection exemption.
- No refresh token, disable state or per-token revocation. Password/rehash/web
  logout do not revoke tokens. Client logout discards its copy; memory-only same-origin
  browser clients re-login after reload/expiry. Copied tokens remain replayable until
  expiry or key-trust removal; HTTPS outside loopback.
- `jwt_keys` volume: owner-only unencrypted PEM, app/console read-only, isolated TEST
  runner read-only, worker no mount. `var/docker/jwt-initialized` independently matches
  volume `.identity` to detect loss. The OpenSSL helper atomically publishes a generation
  symlink and prunes obsolete generations. Native Lexik key-loader arguments lazily
  read `/app/var/jwt/verification.json`; no compile-time keys/dynamic config-tree array.
  Stopped-user `rotate|rotate-emergency|retire` operations retain at most one old public
  key, with operator retirement within 900 seconds of stopping old issuance (downtime
  included), no automatic retirement. Follow README for interrupted first-init
  preserve/restore or explicit disposable-only reset and independent UID/GID ownership
  repair of valuable signing-key storage.

Installed dependencies: LexikJWTAuthenticationBundle **3.2.0**, Lcobucci JWT **5.6.0**,
API Platform Symfony **4.3.19**, 16 newly installed packages; runtime ext-openssl
**8.5.9** was reported by main's initial setup. Locks and recipes are updated; final
setup retained dependencies and keys. Full verification and fresh reviews are complete.
See task 7 for exact acceptance journeys and integration fixes, including narrow
native `Parser::convertDate` TypeError handling for malformed NumericDates (fixed
401, actual HTTP negatives, no raw JWT parser). Actual HTTP/PostgreSQL instrumentation
verifies exactly two account reads with changed email visible in the second query;
the deletion-between-reads 404 has unit coverage. Actual HTTP emergency rotation
rejects the still-unexpired prior token and confirms new issuance. User acceptance
was recorded on 2026-09-14; 8a was verified and reviewed on 2026-09-14 and user accepted on 2026-09-15.

## Accepted 5b API and delivery

```php
$this->eventBus->dispatch(new TaskCreatedEvent($task->id()));
```

- Application injects `App\Platform\Messaging\EventBus`; listeners use ordinary
  `#[AsMessageHandler(bus: 'application.event.bus')]` registration. No extra tags.
- Sync listeners run immediately before the producer's final ORM flush. Their
  commands join the producer transaction. Event failure invalidates the root even
  if caught. Pending writes need not yet be SQL-visible.
- Async dispatch inserts **one native event row** on the default DBAL connection
  inside the producer transaction. Workers use current handlers; their commands
  own normal independent roots. Earlier committed effects can survive later listener
  failure. Native HandledStamps retain partial handler success on ordinary retries;
  crashes and partially successful listeners still require module-owned idempotency
  for every independently committed step, backed by transactional uniqueness.
- Required invariants use explicit nested commands. No global ordering or exactly-once
  external effects are promised. There is no custom recorder/publisher, per-listener
  job/registry, payload codec, event buffer or delivery-budget framework.
- Domain recording remains optional through RecordsDomainEvents/trait. Application
  explicitly selects Domain facts and translates them to public events. Event bases
  remain empty and event/data types remain excluded from services.

## Mode selection and operations

```sh
# Sync is the default.
export EVENT_TRANSPORT_DSN=sync://
./bin/dev up

# Async (after applying migrations with setup):
export EVENT_TRANSPORT_DSN=doctrine://default
./bin/dev up
./bin/dev worker start
./bin/dev worker status
./bin/dev worker stop
```

The shell export reaches app/CLI/worker. It does not belong in the strictly parsed
`var/docker/local.env`. `up` recreates app when its environment changes. Workers
must stop/start after source changes; start refuses sync. Raw Symfony consumption
silently skips sync receivers. Tests explicitly select isolated modes.

Native queues `events` and `events_failed` use `public.platform_messaging_message`
and the retained applied `Platform/Messaging/Resources/migrations/Version20260912010000`.
Preflight found legacy queues empty. Old opaque rows are neither converted nor
deleted; new workers ignore old queue names. Drain old obligations with old code.
Drain native pending/in-flight/failed messages before incompatible mode/DTO/handler
changes. No automatic upcaster exists.

Ordinary retries use 1/2/4-second delays; failed jobs persist for explicit operator
action. Standard `messenger:failed:show|retry|remove` commands have metadata-only
presentation subclasses; native operator retry **can execute handlers inline**.
Diagnostics adapters preserve retry classification and HandledStamps while suppressing
payload/exception dumps. Native malformed-message failure storage may retain original
wire data: queue writers are trusted and stored payloads/backups require restricted access.

Sequential workers reset between jobs and recycle after soft 3600-second/128M/1000-job
limits. The 300-second lease has no keepalive/hard handler deadline in the configured
command; duplicates/overlaps must remain safe. Cleanup failure disables the runtime.
Notification-based receiving/LISTEN is disabled; native sending still emits `pg_notify`.

## Verification and independent review

### Completed Subtask 7 evidence (2026-09-13)

**IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-14.** Main completed
the following verification on 2026-09-13:

| Command | Final result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys, dependencies unchanged, migration current, app/database healthy. |
| `./bin/dev check` | **PASS**: **532 architecture tests / 3289 assertions + 163 unit tests / 1096 assertions = 695 tests / 4385 assertions**; Deptrac **1246 allowed / 0 violations / 0 uncovered**. Audit, static analysis, style, lint, shell/key contracts and offline event fixtures passed. `var/test-runs/run-VU1J5W0M/`. |
| `./bin/dev test` | **PASS**: **201 tests / 4606 assertions**, all **38 PHPUnit phases**. `var/test-runs/run-Dk8kGEH0/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-tr7xoQb4/`; embedded **201 tests / 4607 assertions**, all **38 PHPUnit phases**, at `application/var/test-runs/run-KkpCT8uO/`. Two-instance cross-JWT isolation, hidden input, account/session/key persistence, development/test isolation and cleanup passed. |

Fresh independent authentication reviewer `ses_f63a1e74affeszKsYM4RJDMnZS`
**APPROVED** with no concrete findings. Fresh independent runtime reviewer
`ses_f63a1e72bffePxCpnh11QTnL9Q` **APPROVED** with no concrete blocking findings or
materially missing acceptance coverage. Both inspected code/evidence and did **not**
rerun suites. No further code/configuration changes followed those reviews before
Subtask 7 acceptance; the separate completed 8a verification and review are recorded above.

Three-sample local actual HTTP medians: issuance **527.04 ms**, `/api/me` **21.27 ms**;
consumer **525.65 ms / 21.56 ms**, respectively. These are local observations, not an
SLA. Task 7 records the precise coverage, boundaries and user acceptance on 2026-09-14.
Verification and review dates remain 2026-09-13.

### Accepted Subtask 6 post-correction evidence (2026-09-13)

**Subtask 6 including the correction is VERIFIED, REVIEWED and USER ACCEPTED on
2026-09-13.** Main completed the following actual container/PostgreSQL and consumer runs:

| Command | Completed result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; dependencies and Authenticating migration unchanged; app/database healthy. |
| `./bin/dev check` | **PASS**: **511 tests / 3211 assertions + 99 tests / 731 assertions = 610 tests / 3942 assertions**; Deptrac **1031 allowed / 0 violations / 0 uncovered**. Audit, PHPStan, lint, style and both-mode fixtures passed. `var/test-runs/run-eTQK6EAr/`. |
| `./bin/dev test` | **PASS**: **137 tests / 1991 assertions**, all 21 PHPUnit phases; hashing inside the active transaction, compiled hasher-throw rollback and CLI log checks without exercised credential leaks. `var/test-runs/run-xaiXVV3r/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-fej1lSca/`; embedded **137 tests / 1990 assertions**, all 21 invocations, at `application/var/test-runs/run-1pN3Ocj1/`. Actual PTY/stdin space preservation and confirmation-mismatch tests, repeat setup, account/session preservation, two-consumer isolation and cleanup passed. |

Fresh independent correction reviewer `ses_f64339484ffeQjNteclNnjAlvj` inspected
code and evidence and **APPROVED** with no concrete findings. The earlier two reviewer
approvals cover the unmodified native web-authentication scope; this fresh review
completes review of corrected registration. User acceptance is recorded on 2026-09-13.

### Pre-correction historical evidence only

The following runs and approvals cover the earlier hash-only-registration snapshot,
not the approved registration correction.

| Command | Historical result / evidence |
| --- | --- |
| `./bin/dev setup` | Passed against actual containers/PostgreSQL; Authenticating migration `Version20260913010000` current. |
| `./bin/dev check` | Final **PASS** after all tooling changes: **511 tests / 3211 assertions + 77 tests / 527 assertions = 588 tests / 3738 assertions**; Deptrac **1033 allowed / 0 violations / 0 uncovered**; PHPStan, audit, style, lint and both-mode checks all pass. `var/test-runs/run-hsi0IoyH/`. |
| `./bin/dev test` | Passed: **120 tests / 1744 assertions**, native auth/session/throttle and persistence/outage/recovery alongside existing CQRS/event journeys; `var/test-runs/run-qeu9uWzb/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASSED**: `/tmp/opencode/donmario-setup-RBWCh0mQ/`; embedded **120 tests / 1745 assertions** at `var/test-runs/run-o7vT29al/` within the consumer; hidden PTY provisioning, repeatability, account/session preservation and two-consumer cookie/development-test isolation. |

Pre-correction Subtask 6 reviewers — **both historical rechecks APPROVED**:
- Security `ses_f64d5006fffeSQrj560MwdaAVr`: front-controller ingress bypass fixed;
  actual oversized/streamed HTTP evidence verifies the guards. Recheck approved:
  no remaining concrete findings.
- Runtime/tooling `ses_f64d50049ffelRAvdjNV20My7V`: secret-bearing equality assertion
  output replaced by boolean `hash_equals`; each generation is stopped, then raw logs
  including shutdown output are collected/checked/redacted before recreation/removal,
  with an actual earlier-generation canary fault test; PTY cleanup uses process-group
  bounded TERM/KILL/reap plus a self-test. Recheck approved: **all three P2 findings
  resolved**, no further findings.

Historical consumer verification includes corrected actual PTY newline handling and
case-insensitive Python cookie-attribute comparisons. Storage verification matches native
flock suffixes including `+` and expected empty 0666 files under 0700 directories.
Actual private session/limiter/lock inspection and log canaries establish exercised
paths only. Bounded resource diagnostics and source checks are not universal secrecy
proofs. See task 6 for corrections, exact evidence and the local native-hashing timing
observation. These historical checks and reviewer rechecks apply to the earlier
snapshot; the completed post-correction runs and fresh approval above establish
the corrected registration checkpoint, accepted by the user on 2026-09-13.

Historical accepted 5b evidence remains in its task record: **496 tests / 3226
assertions**, Deptrac **747 allowed / 0 violations / 0 uncovered**, E2E **88 tests /
1270 assertions**, and successful fresh-consumer verification. Its independent
review approvals are not Subtask 6 approvals.

The consumer export honors tracked working-tree deletions and untracked additions
without modifying the Git index. Use `./bin/dev` for PHP/Composer/checks. Do not rerun
unchanged passing suites merely to resume.

At the pre-correction checkpoint, main's actual `docker ps` showed the project's
`dm-1462298262-app-1` and `dm-1462298262-database-1` healthy, with the worker stopped
and no consumer remnants. Development was in sync mode. This is historical runtime
state; the completed post-correction setup also confirmed app/database health,
and fresh-consumer verification completed cleanup.
Preserve local credentials and evidence, but do not treat the old instruction to retain an
intermediate authorization database as a compatibility requirement: the clean model uses
a recreated database. Evidence directories may contain private settings; share only
redacted logs. Tests must use isolated data.

## Historical fresh-session prompt — superseded

The prompt below predates 8b acceptance and Task 9 implementation. Retained for
history only; use the current checkpoint and next gate at the top of this handoff.

> Read `docs/handoff.md`, `AGENTS.md`, `README.md`, `docs/architecture.md`,
> `docs/roadmap.md` and main-owned `docs/tasks/08-authorizing.md`; inspect `composer.json`,
> `composer.lock`, `symfony.lock` and current changes. The tree was clean at existing
> `c29a3f9` before 8a; 8a is now accepted and pushed as `367fdfe` to `origin/main`.
> Preserve the intended uncommitted 8b work. On 2026-09-15 the user replied exactly
> “commit, push and proceed” to the request to accept 8a and proceed to 8b. This accepts
> 8a, authorizes commit/push of the verified 8a checkpoint only, and approves 8b implementation.
> That commit/push is complete and applied only to 8a. Main owns the Git workflow
> and task 8's record. Future commits/pushes need explicit authorization; reconcile
> subsequent main/user updates before acting.
> Subtask 7 remains accepted on 2026-09-14; its passing historical evidence does not
> verify 8a. The user approved “proceed”/“continue” for the split design. Subtask 8a
> is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15, the latest accepted checkpoint. Earlier fixture
> YAML/static issues are resolved; final setup/check/E2E/consumer verification passed
> and both fresh independent implementation reviewers approved with no findings on 2026-09-14.
> Reviewers did not run suites. Final check and consumer E2E cover the final compiler
> guard; standalone E2E preceded it, with runtime unchanged. Do not repeat passing
> suites merely to resume. Preserve native authentication/EventBus/CQRS and exact key boundaries.
> 8a adds synchronous CQRS/Input collections only; runtime needs no PHPDoc parser.
> 8b is IMPLEMENTED, VERIFIED, REVIEWED — AWAITING USER ACCEPTANCE on 2026-09-15:
> four mapped tables/Domain catalogue, three batch-capable APIs plus owning
> Authenticating CheckAccountExistence, and eight trusted-operator console commands.
> No application-actor enforcement or new HTTP/API management routes. Final setup,
> check, all 42 E2E phases and consumer verification passed, including actual N=100
> budgets and populated plans; see the completed 8b evidence above for exact runs and
> both fresh independent reviewer approvals. Neither reviewer reran suites; no code
> changes followed review, only docs. Earlier cursor/EXPLAIN fixes are covered by final
> passing runs. Obtain 8b user acceptance before Task 9 discovery/design only;
> implementation requires its own design approval. Do not describe 8b as user accepted
> or repeat unchanged passing suites merely to resume. Preserve README scope/JSON/cursor
> contracts and architecture snapshot, transaction and ownership boundaries.
