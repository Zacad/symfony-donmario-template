# Task 10 correction - Global Authorizing, native voters and Task ownership

## Current Gate - 2026-09-22

**The user approved the clean Authorizing model described below. Implementation, focused
verification, full fresh setup/check/E2E/fresh-consumer verification and fresh independent
review are complete; user acceptance is pending.** This approval supersedes the verified but unaccepted
intermediate reworks recorded later in this document. It does not authorize Task 11, a
commit or a push.

This is a fresh-template-only correction. Compatibility with schemas or rows created by
the intermediate Task 10 authorization designs is explicitly not required. Existing
databases using those designs must be disposed and recreated; no compatibility migration,
fallback read, inert legacy table or cleanup API is part of the approved model.

### Clean Persistence Baseline

`Version20260915010000` is rewritten as the complete Authorizing baseline.
`Version20260917010000` and `Version20260920020000` are deleted. The module owns exactly:

| Table | Identity and purpose |
| --- | --- |
| `authorizing_role` | `role_key`; active/retired runtime role definition with label and revision |
| `authorizing_role_permission` | `(role_key, permission_key)`; role membership |
| `authorizing_role_assignment` | `(subject_id, role_key)`; subject role assignment |
| `authorizing_permission_grant` | `(subject_id, permission_key)`; subject direct grant |

There are no resource-role or resource-permission tables, initial-binding table,
scope/resource columns, `account_id` columns or synthetic assignment IDs. Assignment rows
use their natural identities. The role-assignment and role-permission foreign keys retain
role integrity; opaque `subject_id` values retain no cross-module foreign key.

### Domain And API Model

Authorizing Domain types remain grouped by Assignment, Capability and Role business
concepts. Explicit technical suffixes (`Entity`, `ValueObject`, `Enum`, `Service`) are an
Authorizing-only convention, not a repository-wide naming rule. Concepts are reasons to
change, so technical roles stay inside their concept instead of creating `Domain/Mapping`,
`Domain/Entity`, `Domain/ValueObject` or placeholder directories. `Entity` covers both rich
lifecycle owners and narrow persisted relationships; `ValueObject` means immutable
validated identity-free data; `Enum` means a closed backed vocabulary; `Service` means a
stateless Domain collaborator; persistence ports retain `Repository`. Cross-concept
validators and exceptions may remain at the Domain root. Application DTO suffix rules are
separate and unchanged.

- `RoleEntity` is the rich role model. It owns creation, create-if-absent matching,
  revisioned label/permission definition and irreversible retirement. Repositories
  persist and reconstitute it; they do not duplicate lifecycle decisions.
- `RolePermissionMembershipEntity`, `RoleAssignmentEntity` and
  `PermissionGrantEntity` map the other three tables. DBAL repositories retain bounded
  set-based reads/writes and transaction ownership remains outside repositories.
- `AssignmentReferenceValueObject(kind, key)` represents both a stored assignment and
  the Domain continuation reference. `AssignmentChangeValueObject(operation, reference)`
  represents one mutation. `AssignmentChangeCountsValueObject(added, removed)` reports
  repository effects. There is no `ChangeSet`, `StoredAssignment` or Domain cursor.
- Public mutation input is exactly `operation`, `kind`, `key`. Public assignment rows are
  exactly `kind`, `key`; cursor DTOs add only their subject binding. No public assignment
  contract exposes a resource coordinate, scope, account column or assignment ID.

The public use cases remain `ChangeSubjectAssignments`, `ListSubjectAssignments`,
`EvaluateSubjectEntitlements` and the bounded role/capability APIs. Authorizing remains
generic, subject-based and independent of Authenticating and TaskTracking. Assignment
management still admits `assignments` operators or raw global `authorizing.manage`;
catalogue management still admits `catalogue` operators or raw global
`authorizing.catalogue.manage`. Raw evaluation may target orphan subjects and unknown
permissions deny.

### Preserved Authorization Semantics

- Every command/query handler still has exactly one `#[Authorize]`. Restricted forms name
  a concrete same-module voter; actor-unrestricted forms use only
  `#[Authorize(public: true)]`. Module permission enums, `CqrsPass`, the compiler-selected
  Platform public voter and isolated `AccessDecisionManager` with
  `UnanimousStrategy(false)` remain.
- Permissions, roles and direct grants remain global and additive. Active role
  definitions/memberships participate in each entitlement query; retired assignments are
  listable/removable but grant nothing. Cross-module permission bundles, the 4096 active
  membership-edge bound, immutable role keys, expected revisions and exact
  create-if-absent behavior remain.
- Setup role snapshots remain `task_tracking.user`, `authorizing.administrator` and
  `application.administrator`; future capabilities are not added automatically.
- TaskTracking continues to own account eligibility and immutable Task ownership checks.
  Task creation makes no authorization assignment. Existing Task pagination, completion
  locking, `(owner_account_id, id)` migration and business-read budgets remain.
- Subject-assignment and role-catalogue advisory-lock namespaces remain stable. DBAL
  repositories, one-read raw evaluation, one-query assignment listing, actor/event frames,
  caught-failure poisoning and transaction/result ordering remain.

### Acceptance Criteria

1. Fresh setup produces exactly the four Authorizing tables from rewritten
   `Version20260915010000`; the two superseded authorization migration files are absent.
2. Source, metadata and actual-schema checks reject any resource/initial-binding table,
   scope/resource or `account_id` column, synthetic assignment ID or compatibility path.
3. Role lifecycle behavior is owned by `RoleEntity`; Assignment uses the approved
   reference/change/count value objects and no `ChangeSet`, `StoredAssignment` or Domain
   cursor remains.
4. Public mutation/list/cursor contracts expose only the approved fields. Natural-key
   idempotency, role retirement, unknown-permission denial, defaults and operator/account
   authority continue to work.
5. Existing native-voter behavior, Task ownership rules, lock namespaces, SQL budgets,
   transaction/event guarantees and PostgreSQL N=100 paths remain covered.
6. `./bin/dev setup`, `./bin/dev check`, `./bin/dev test` and
   `TMPDIR=/tmp/opencode ./bin/dev verify-setup` pass, followed by fresh independent
   security/runtime and persistence/architecture review before user acceptance.

### Security And Performance Review

Removing compatibility surfaces is fail-closed: no legacy resource row can grant or be
mistaken for global authority because no such schema or read path exists. Natural keys
remove identifier substitution and make duplicate add/remove behavior database-enforced.
Opaque subjects still avoid cross-module coupling, so business voters must continue to
compose account existence and ownership. Rich role lifecycle methods retain revision and
retirement invariants before DBAL persistence.

The clean model does not add reads, loops or caches. Assignment mutation keeps the exact
subject transaction advisory lock and bounded grouped DML; role changes keep the role
catalogue lock and aggregate edge bound. Raw entitlement remains one bounded SQL read,
assignment listing one bounded keyset query over role then permission natural keys, and
the established Task read budgets remain unchanged. Snapshot races and already-authorized
work limits remain as previously documented.

### Progress

| Stage | State |
| --- | --- |
| Clean-model design approval | **COMPLETE**, 2026-09-22 |
| Implementation | **COMPLETE** |
| Focused verification | **PASS: 723 / 4280** |
| Fresh setup/full check/E2E/fresh consumer | **COMPLETE** |
| Fresh independent review | **COMPLETE**: both reviewers APPROVED with no findings after recheck |
| User acceptance | **PENDING** |

### Clean-Model Verification Evidence

| Exact command | Result and evidence |
| --- | --- |
| Focused affected PHPUnit selection | **PASS: 723 tests / 4280 assertions** across Domain lifecycle, Application handlers/console, DBAL repositories, CQRS/source boundaries, service compilation, migration inventory and Doctrine metadata |
| Post-review focused PHPUnit selection | **PASS: 128 tests / 288 assertions** across role lifecycle/persistence and compiler authorization metadata |
| `./bin/dev check` | **PASS**, `var/test-runs/run-K22OUIXu/`: **1147 tests / 6248 assertions**; PHPStan and style clean; Deptrac **2285 allowed / 0 violations / 0 uncovered**; source, container, collection, persistence-metadata and migration-inventory checks passed |
| `./bin/dev test` | **PASS**, `var/test-runs/run-MqAHjmgS/`: **246 tests / 6631 assertions**, all **53 phases**, including clean schema, Authorizing behavior/outage recovery, populated N=100 assignment mutation/list plans, Task ownership/query budgets and authorization enforcement |
| `./bin/dev verify-setup` | **PASS**, `/tmp/donmario-setup-T6lsRfg6/`; embedded `application/var/test-runs/run-LWKUVPhh/`: **246 tests / 6631 assertions**, all **53 phases**, plus clean checkout/setup/repeat setup, exact default snapshots, assignment persistence, removed-schema absence, Task ownership and consumer isolation checkpoints |

The existing repository-local development database still records the two deliberately
deleted intermediate migrations. A direct `./bin/dev setup` therefore refused its
incompatible default-role state as required by the fresh-template-only cutover; no local
development data was destroyed. Fresh `./bin/dev setup` execution and repeat setup passed
inside the generated consumer above.

Initial security/runtime reviewer `ses_f35d3a93dffeUpQzpNFkQkqQrG` found that retirement
was exposed as two public entity transitions and that clean natural-key assignment
mutation/listing lacked N=100 PostgreSQL proof. `RoleEntity::retire()` now atomically sets
revision and database-supplied retirement time, rejects retired revision-one reconstitution,
and has focused repository/Domain coverage. E2E now proves grouped N=100 add and mixed
remove/add counts, a 100-row continuation and an analyzed populated plan using
`authorizing_role_assignment_pkey`.

Initial architecture/evidence reviewer `ses_f35d3a923ffeRNh6GdBk2tcT3v` found stale status
wording, incomplete `*PermissionEnum` guidance, one stale code-map path and missing direct
negative compiler tests. Current documentation is reconciled and compilation fixtures now
reject integer-backed, wrong-suffix, malformed-key, wrong-prefix and wrong-layer permission
enums. Both reviewers rechecked the final tree and evidence without rerunning suites.
Security/runtime reviewer `ses_f35d3a93dffeUpQzpNFkQkqQrG` and architecture/evidence
reviewer `ses_f35d3a923ffeRNh6GdBk2tcT3v` each **APPROVED with no findings** and no
material coverage gap. The latter notes only the intentional incompatibility of the
existing intermediate development database; fresh and repeat consumer setup are the
applicable proof.

After those reviews, the user requested removal of the empty physical `Domain/Mapping`
directory and durable documentation of the organization/naming decisions. The directory
is removed, and the active architecture, engineer guide, agent instructions and handoff
now record concept-first folders, exact Authorizing-local suffix meanings, root placement
for genuinely cross-concept rules and the prohibition on technical/placeholder namespaces.
Historical `Domain/Mapping` descriptions remain only as explicitly superseded provenance.
Focused documentation reviewer `ses_f32b82d86ffehclt4pcxs7aept` **APPROVED with no
findings**; `git diff --check` passed and no behavioral suite was rerun for this docs/empty-
directory-only follow-up.

## Superseded Intermediate Rework - Historical Provenance

Everything below records earlier approved and verified intermediate designs accurately.
Those runs and reviews remain regression provenance, but they do not verify the clean
baseline and their compatibility/schema instructions are not current.

## Historical Approved Post-Rework Cleanup

The user approved this bounded cleanup with exact `proceed` on 2026-09-21. It removes
dead implementation surfaces and stale verification material without changing the approved
authorization model, adding adapters/features or dropping compatibility data.

### Scope And Retained History

- Remove the no-op entitlement-check validation path and redundant dependency; keep
  unknown-permission masking. Make the role-catalog lock an exact private exclusive
  repository detail while preserving its namespace and call positions.
- Replace unread authorization message names with guarded frame depth. Rename the removed
  Application-policy decision phase and diagnostics to authorization-decision terminology.
- Convert all seven SQL-owned Authorizing entities to behaviorless Doctrine mapping shells.
  Preserve every table, column, index, association, physical `account_id`, scope/resource
  compatibility field and migration, including `Version20260915010000`,
  `Version20260917010000` and `Version20260920020000`.
- Convert NativeEvents' synthetic allow-all voter to compiler-inventoried public actions;
  delete its voter/wiring and stale inherited generated-module exclusions. Remove unused
  fixture role seeds and the consumer's hand-built capability-shape claim.
- Rename stale policy-era tests/current wording, make synthetic descriptors explicit and
  consolidate duplicate no-auto-grant SQL assertions while retaining dedicated Task E2E,
  consumer assignment-stability, inert-resource and migration coverage.
- Reconcile current status and final amendment evidence. Add supersession notices to Task
  8/9 without deleting historical bodies, run/review IDs, acceptance quotes or provenance.
  Retain unrelated `MessagePolicy`, `EventPolicy`, `PasswordPolicy` and historical policy
  terminology where accurate.
- Retain subject assignment/evaluation and role/capability APIs. Resource rows remain inert,
  non-addable and list/remove-only. Historical account/resource/initial-binding APIs remain
  provenance only. Add no role/catalogue adapters or other features.

### Cleanup Acceptance Criteria

- No no-op catalogue check, public lock port, unread authorization message-name stack,
  synthetic allow-all NativeEvents voter, unused runtime-role fixture seed or fake consumer
  capability result remains.
- NativeEvents public handlers use only `#[Authorize(public: true)]`; generated fixtures do
  not inherit TaskTracking's voter exclusion and fail visibly if the prototype drifts.
- Authorization decision frames retain actor pinning, support-read provenance, nested
  failure poisoning and transaction invalidation. Unknown permissions still deny and role
  mutation retains the exact exclusive advisory lock.
- Doctrine metadata and physical schema are unchanged. Inert resource rows and initial
  bindings remain compatible, listable/removable where supported and unable to grant.
- Dedicated behavioral coverage remains while duplicate legacy-table assertions and stale
  names/current descriptions are removed.
- Setup, full check, standalone E2E and fresh-consumer verification pass, followed by fresh
  independent security/runtime and persistence/test review. User acceptance remains a
  separate gate before Task 11 or Git delivery.

### Cleanup Security And Performance Review

Compiler-inventoried public fixture messages still traverse the isolated decision manager;
they gain no capability, SQL or HTTP route. Actor/transaction/event frames and fail-closed
unknown permissions remain unchanged. The role lock remains exclusive under the same
PostgreSQL advisory namespace. Mapping-shell simplification changes no metadata or schema
and removes accidental construction APIs for inert rows. No production query path, SQL
budget, package or migration changes; consolidated tests retain one dedicated behavior
proof instead of repeatedly coupling unrelated journeys to legacy tables.

### Cleanup Progress

| Stage | State |
| --- | --- |
| User cleanup approval | **COMPLETE**: exact `proceed`, 2026-09-21 |
| Implementation and focused verification | **COMPLETE** |
| Focused affected PHPUnit selection | **PASS: 510 / 3917** |
| Setup/full check/E2E/fresh consumer | **COMPLETE** |
| Fresh independent cleanup review | **COMPLETE**: both reviewers APPROVE with no findings |
| User acceptance | **PENDING** |

Full evidence and review are recorded below. User acceptance remains a separate final gate.

Focused cleanup verification ran the affected Authenticating security, authorization voter/
runtime, Authorizing application/console/domain/role, TaskTracking application and CQRS/
event/source-rule architecture PHPUnit files through `./bin/dev composer exec -- php
vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php ...`; all **510 tests /
3917 assertions** passed.

## Historical Approved Domain Organization Follow-Up

After the initial cleanup, the user raised the flat `Authorizing/Domain` directory,
`Role`/`RoleDefinition`, `AuthorizationCatalog` and overall cleanup completeness. The user
selected **“Bounded full cleanup (Recommended)”** on 2026-09-21.

- Seven behaviorless Doctrine classes moved to `Domain/Mapping`; root Domain now contains
  usable values, behavior and repository ports. Physical metadata and schema are unchanged.
- `Role` remains the repository snapshot; `Mapping\RoleDefinition` is visibly table metadata.
- `AuthorizationCapabilityRegistry` was merged into the compiler-fed
  `AuthorizationCatalog`, which now owns installed-key validation and bounded descriptor
  listing from one inventory.
- Internal values were clarified as `RolePermissionSet`, `AssignmentChangeSummary`,
  `AuthorizationSyntax`, `EntitlementCheck` and `StoredAssignment`.
- The subject advisory lock moved inside `DoctrineAssignmentRepository::change()`, removing
  an easy-to-misuse two-step Domain port while preserving its namespace and ordering.
- Stale test descriptions/data, assignment-source documentation and the misplaced mapping
  comment were corrected. Direct active/retired/not-found `GetRole` and exact 4096/4097
  permission-set coverage were added. The engineer guide now maps every Domain type.
- Public Application DTOs, cursor integers, CLI syntax, permissions, role behavior,
  migrations, SQL shapes and business-read budgets are unchanged.

Security improves by making subject serialization unavoidable for assignment mutation.
Unknown permissions still deny, catalogue data still comes only from `CqrsPass`, and no
new write or authorization path exists. The single descriptor catalogue removes duplicate
in-memory keys and adds no SQL.

Final evidence is check `run-kydGbTrF` (**1119 / 6173**, Deptrac **2319 / 0 / 0**),
standalone E2E `run-lRiRb342` (**244 / 6545**, all **53 phases**) and fresh consumer
`/tmp/opencode/donmario-setup-6Cyl5Sod/` with embedded `run-6Cflj2DF`
(**244 / 6541**, all **53 phases**) plus setup/persistence/isolation checkpoints. The final
check followed review-driven test/documentation corrections; runtime was unchanged after
the passing standalone/consumer E2E runs.

Fresh implementation reviewer `ses_f3ac53f4effej7rD4xiCuPuzyC` approved with no findings.
Fresh tests/documentation reviewer `ses_f3ac53e51ffegJQaa52DELgFvh` found four coverage and
wording issues; all were corrected, the final check passed, and the reviewer approved on
recheck with no remaining findings. Neither reviewer edited files or reran suites.

### Approved concept namespace follow-up

After reviewing the type classification, the user approved business-concept organization
on 2026-09-21. Active assignment values and their repository port move under
`Domain/Assignment`, the compiler-fed catalogue and permission enum under
`Domain/Capability`, and role values and their repository port under `Domain/Role`.
Shared syntax and exception types remain at the Domain root. The seven behaviorless
Doctrine shells remain under exact `Domain/Mapping`; an experimental `Domain/Entity `
path with a trailing space is removed. This changes internal namespaces only, with no
public DTO, SQL, schema, migration, permission, lock, transaction or runtime behavior
change.

Focused Domain/application/repository/compiler/source verification passed **418 tests /
3500 assertions**. Full check `run-P3J7V3eL` passed **1119 / 6173**, with PHPStan clean,
Deptrac **2319 / 0 / 0**, and clean persistence metadata/source architecture. Standalone
E2E `run-gmpy3e9m` passed **244 / 6538**, all **53 phases**. Fresh consumer
`/tmp/donmario-setup-YLUpCxuq/` passed with embedded `run-GEsnErrk` at **244 / 6547**,
all **53 phases**, plus setup, exact authorization-default, persistence and isolation
checkpoints. Fresh implementation reviewer `ses_f3a72b8d2ffev4JHDkDdRoGAlQ` and fresh
documentation/coverage reviewer `ses_f3a72b8c2ffeQvI5DX3fdNGLwf` each found the same
empty trailing-space directory left by the moves. It was removed; both reviewers approved
on recheck with no remaining findings and did not edit files or rerun suites.

## Historical Implemented Intermediate Core Design

### Declarations And Native Admission

- Every command/query handler declares exactly one `#[Authorize]`. Restricted
  declarations name a concrete final same-module voter. Permission-bearing declarations
  also name a module-owned string-backed `*Permission` enum case and stable label;
  contextual declarations omit permission and label. Actor-unrestricted declarations use
  only `#[Authorize(public: true)]`.
- `CqrsPass` aggregates global installed capabilities and validates enum ownership,
  stable metadata, mutually exclusive public/restricted forms, voter routing and the exact
  private voter iterator. Public actions produce no capability and cannot be assigned or
  revoked through roles/grants. There is no authorization YAML, resource enum/partition,
  Application policy service or locator.
- One cohesive private lazy Symfony voter per module is evaluated by a dedicated private
  `AccessDecisionManager` over only `app.authorization.voter`, using
  `UnanimousStrategy(false)`. It does not replace firewall authorization and remains
  untraced so command/query subjects are not retained by authorization tracing.
- The compiler selects the exact private lazy Platform `PublicAccessVoter` for public
  metadata. Handlers cannot reference it. It recognizes only compiled public messages,
  accepts only the internal token, has no dependencies/SQL and is untagged when unused.
  Public means actor-unrestricted bus admission, not transport or firewall exposure.
- `AuthorizationToken` remains credential-free and carries only immutable Actor and
  support-read provenance. Invocation/transaction ownership and event frames remain in
  their infrastructure contexts. Input/transaction/admission/result/event ordering,
  caught-failure poisoning and frame cleanup remain.

### Global Authorizing Model

Authorizing remains generic: it has no Authenticating or TaskTracking imports, account
lookup, owner lookup or business-resource SQL. Its current public use cases are:

| API | Purpose |
| --- | --- |
| `ChangeSubjectAssignmentsCommand` | Atomic bounded global role/direct-grant changes for one opaque subject; legacy resource rows may only be removed. |
| `ListSubjectAssignmentsQuery` | Bounded inventory including orphan, retired and inert legacy resource rows. |
| `EvaluateSubjectEntitlementsQuery` | Raw bounded global decisions in one business SQL read; unknown permissions deny. |
| `ListAuthorizationCapabilitiesQuery` | Bounded installed global capability catalogue. |
| `DefineRoleCommand`, `GetRoleQuery`, `ListRolesQuery`, `RetireRoleCommand` | Revisioned runtime-role lifecycle. |

Generic resource access/grant and initial-binding APIs are removed. PHP fields, batch
JSON and cursors use `subjectId`; physical `account_id` columns, assignment UUIDs and the
subject advisory-lock namespace remain.

Assignment management admits an `assignments` operator or an account actor with raw
global `authorizing.manage`. Catalogue operations admit a `catalogue` operator or an
account actor with raw global `authorizing.catalogue.manage`. Raw evaluation may operate
on orphan subjects. There is no authorization cache or JWT/session permission authority.

Effective permissions are the additive union of active globally assigned roles and
global direct grants. There is no wildcard. A role may explicitly combine any installed
permissions across modules. Role keys are immutable; label/bundle updates require the
expected revision; retirement is irreversible. Retired assignments remain listable and
removable but grant nothing. There is no separate per-role permission maximum; the total
4096 active role-permission membership-edge bound remains. Create-if-absent accepts only
an exact active match.

### Defaults And Legacy Schema

Setup integration must seed these exact global snapshots:

| Role | Explicit permission snapshot |
| --- | --- |
| `task_tracking.user` | `task_tracking.task.create`, `task_tracking.task.view`, `task_tracking.task.complete` |
| `authorizing.administrator` | `authorizing.manage`, `authorizing.catalogue.manage` |
| `application.administrator` | All five permissions currently installed above |

Future capabilities are not added automatically, including to
`application.administrator`. That role is not a business-context bypass: module voters
still enforce actor kind, account existence, ownership and other contextual predicates.

The four historical assignment/grant tables remain physically present. Global role and
direct-grant rows remain active. Resource role/direct-grant rows grant nothing, cannot be
added, and remain listable/removable only for cleanup. Retained role scope/resource
columns and `authorizing_initial_resource_role` are inert; no current API resolves or
mutates an initial binding. `Version20260917010000` and prior authorization migration
history remain intact. Conditional migration `Version20260920020000` restores definitions
only for persisted historical global `task_tracking.creator`, `task_tracking.reader` and
`task_tracking.editor` assignments, preserving their former explicit Task permissions.

### TaskTracking Ownership

TaskTracking's concrete voter owns account existence and Task ownership checks. The
approved admission matrix is:

| Use case | Account actor | `tasks` operator |
| --- | --- | --- |
| Create | Persisted self owner plus global `task_tracking.task.create` | May create unowned or for any persisted owner |
| Get | Persisted actor, global `task_tracking.task.view`, existing Task and exact ownership | May get any Task |
| List | Persisted self owner target plus global `task_tracking.task.view` | May list all Tasks or filter with `--owner` |
| Complete | Persisted actor, global `task_tracking.task.complete`, existing Task and exact ownership | May complete any Task |

Missing, foreign and unowned Tasks deny account Get/Complete. Global permission alone is
insufficient. Create records immutable ownership and emits the existing creation event,
but performs no automatic authorization role/direct grant. Ownership is contextual state,
not an Authorizing assignment.

List uses `ownerAccountId`, CLI `--owner` and owner-bound opaque cursors. Pages remain
1-100/default 50 in UUID keyset order. Forward-only Task migration
`Version20260920010000` adds `task_tracking_task_owner_id_idx` on
`(owner_account_id, id)`. Existing authorization schema is retained.

Expected business SQL reads, excluding authentication, are:

| Journey | Maximum reads |
| --- | --- |
| Raw entitlement batch | **1** |
| Account Create | **2** |
| Account Get | **3** |
| Account List | **3** |
| Account Complete | **4** |
| Operator List | **1** |

These budgets and the owner-index path pass actual PostgreSQL/E2E verification at N=100.
Authorization, account and Task reads are snapshots; deletion/revocation/ownership races
may allow already-authorized work to finish. Completion locking and first-timestamp
semantics remain unchanged.

## Historical Intermediate Acceptance Criteria

- Every handler has exactly one `#[Authorize]`; restricted forms name one concrete
  same-module voter and public forms use only `public: true`. Bare/mixed forms fail. No
  resource declaration or authorization YAML remains.
- Public command/query routes admit all internal actor kinds through only the exact
  compiler-inventoried Platform voter, add no capability, perform no authorization SQL and
  do not imply HTTP/transport exposure.
- Roles/direct grants and raw evaluation are global; explicit cross-module role bundles
  work; wildcard and automatic future-capability expansion do not exist.
- Exact three defaults are seeded idempotently as snapshots and incompatible existing
  definitions fail visibly rather than being overwritten.
- Legacy resource rows are inert, cannot be added, grant nothing and remain listable/
  removable. Initial-binding APIs are absent and retained schema is inert.
- Account Task Create/Get/List/Complete enforce the admission matrix; operator bypass,
  missing/foreign/unowned denial and no-create-auto-grant behavior are proven.
- Owner-filtered pagination, cursor binding, `(owner_account_id, id)` migration/index and
  the expected SQL budgets are proven against actual PostgreSQL at N=100.
- Setup, full check, standalone E2E, fresh consumer and outage/recovery pass, followed by
  fresh independent authorization/security and persistence/performance review.

## Historical Intermediate Security And Performance Review

- Coarse global permission never replaces contextual voter checks. The all-five
  administrator snapshot therefore cannot bypass Task ownership or account existence.
- Native voter scanning is bounded by the installed private voter set;
  `supportsAttribute()` and `supportsType()` remain pure/database-free.
- Public admission remains inside the same middleware/decision-manager path. The exact
  public voter duplicates compiler route inventory, denies non-internal tokens, performs
  no SQL and is absent from the voter iterator when unused. Public mutation is a static,
  non-revocable deployment decision; ingress controls remain separate.
- Resource rows are fail-closed legacy data. Removing write/evaluation paths prevents old
  resource ACL state from silently granting current access while preserving cleanup.
- Runtime role mutation remains revisioned and lock-protected. Cross-module bundles are
  explicit snapshots, not wildcard expansion.
- Raw entitlement evaluation is one bounded SQL read. Task reads avoid per-item
  authorization queries and owner listing uses the composite owner/ID index.
- Fixed denial diagnostics remain payload-free. These controls do not claim in-memory
  erasure, universal SQL plans or race-free snapshots.

## Historical Intermediate Progress And Evidence

| Stage | State |
| --- | --- |
| User design/implementation approval | **COMPLETE**: exact `proceed`, 2026-09-20 |
| Public-action amendment approval | **COMPLETE**: exact `proceed`, 2026-09-20 |
| Core implementation | **COMPLETE** |
| Compiler-focused evidence | **PASS: 499 / 3658** |
| Authorizing-focused evidence | **PASS: 41 / 154** |
| Task-focused evidence | **PASS: 36 / 127** |
| Scoped PHPStan/style/syntax | **PASS** |
| Integration | **COMPLETE**: setup/defaults, consumer tooling, E2E fixtures and migration/index metadata |
| `./bin/dev setup` | **PASS**: retained dependencies/JWT keys, applied through `Version20260920020000`, healthy app/database |
| `./bin/dev check` | **PASS**, `var/test-runs/run-bugsGQQV/`: architecture **846 / 4589**, unit **256 / 1493**, total **1102 / 6082**; Deptrac **2326 allowed / 0 violations / 0 uncovered** |
| `./bin/dev test` | **PASS**, `var/test-runs/run-0dhcVcQ5/`: **243 / 6551**, all **53 PHPUnit phases**, including legacy-role migration/no-overwrite, stale-authority denial, N=100 index plans, atomicity and outage/recovery |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**, `/tmp/opencode/donmario-setup-5WO4i6pr/`; embedded **243 / 6549**, all **53 phases**, at `application/var/test-runs/run-X2HZiZKb/`, plus exact defaults/global grants/inert legacy/owner-bound Task persistence checkpoints |
| Fresh independent review | **COMPLETE**: both final reviewers APPROVE with no findings |
| Public-action amendment check | **PASS**, `var/test-runs/run-YdFZCQO0/`: architecture **860 / 4660**, unit **257 / 1500**, total **1117 / 6160**; Deptrac **2338 / 0 / 0** |
| Public-action amendment standalone E2E | **PASS**, `var/test-runs/run-mcbPhvEu/`: **243 / 6551**, all **53 phases**, including compiled anonymous public command/query execution and protected-route regressions |
| Public-action amendment fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-GH880Uk2/`; embedded `run-7e3fyExj`: **243 / 6549**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Public-action amendment security/architecture review | **APPROVED with no findings**, `ses_f3faeadd9ffekQfHYhpA16htvB`; no suites rerun |
| Public-action amendment implementation/runtime/test review | **APPROVED with no findings**, `ses_f3faead01ffe3ztZxIfDdppVk6`; non-blocking stale Collections README wording corrected; no suites rerun |
| Post-rework cleanup setup | **PASS**: dependencies/JWT keys/migrations retained; app/database healthy |
| Post-rework cleanup focused PHPUnit selection | **PASS: 510 / 3917** across the affected authorization/runtime/unit/architecture suites |
| Post-rework cleanup check | **PASS**, `var/test-runs/run-1wjNygkA/`: architecture **860 / 4660**, unit **258 / 1508**, total **1118 / 6168**; Deptrac **2321 / 0 / 0** |
| Post-rework cleanup standalone E2E | **PASS**, `var/test-runs/run-jKMu34tA/`: **244 / 6547**, all **53 phases** |
| Post-rework cleanup fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-GnWbKpP7/`; embedded `run-haQZJCKf`: **244 / 6547**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Post-rework cleanup security/runtime review | **APPROVED with no findings**, `ses_f3c575379ffee3DS6e7LpzI86H`; no suites rerun |
| Post-rework cleanup persistence/tests/docs review | **APPROVED with no findings**, `ses_f3c57534fffe0ulEUkve8TXPLI`; no suites rerun |
| Domain organization follow-up final check | **PASS**, `var/test-runs/run-kydGbTrF/`: architecture **860 / 4656**, unit **259 / 1517**, total **1119 / 6173**; Deptrac **2319 / 0 / 0** |
| Domain organization follow-up standalone E2E | **PASS**, `var/test-runs/run-lRiRb342/`: **244 / 6545**, all **53 phases** |
| Domain organization follow-up fresh consumer | **PASS**, `/tmp/opencode/donmario-setup-6Cyl5Sod/`; embedded `run-6Cflj2DF`: **244 / 6541**, all **53 phases**, plus setup/persistence/isolation checkpoints |
| Domain organization follow-up reviews | **APPROVED with no findings**, `ses_f3ac53f4effej7rD4xiCuPuzyC` and `ses_f3ac53e51ffegJQaa52DELgFvh`; no suites rerun |
| User acceptance | **PENDING** |

No package/lock change is part of the approved redesign, amendment or cleanup. Verification
and fresh review of the redesign, amendment and cleanup are complete. Cleanup user
acceptance remains pending. Do not start Task 11, commit or push.

## Post-Rework Cleanup Fresh Review - 2026-09-21

Fresh security/runtime reviewer `ses_f3c575379ffee3DS6e7LpzI86H` found only stale current
status wording. Fresh persistence/tests/docs reviewer `ses_f3c57534fffe0ulEUkve8TXPLI`
found the same stale wording plus missing explicit focused-suite evidence. The documentation
was corrected to record completed verification and the **510 / 3917** focused result. Both
reviewers rechecked the final tree and **APPROVED with no findings**; neither edited files or
reran suites. The only noted residual gaps are the pre-existing lack of a deliberately
concurrent assignment-versus-retirement PostgreSQL interleaving test and no direct
`leaveAuthorizationDecision()` underflow test; its sole production caller remains paired in
`finally`.

## Fresh Independent Review - 2026-09-20

Initial read-only reviewers found the upgrade/cardinality defects and documentation/test
gaps that were resolved before final review. `ses_f4113498fffeODtSl6jfJXvA7T` identified
historical global Task roles losing authority and the unapproved 512-role/32-roles-per-
permission limits. `Version20260920020000` now conditionally restores those historical
roles without overwriting existing definitions, and only the approved 4096-edge aggregate
limit remains. `ses_f4113499cffeVcWX7PNbvlL12l` prompted explicit PostgreSQL regressions
for stale unknown grants and undefined-role additions, plus correction of the token facts
documentation. The implementation already masked unknown permissions and validated role
additions under a shared catalogue lock; the new tests make those guarantees explicit.

Final authorization/security reviewer `ses_f40e6a137ffexGfGutmbAoM5lt` and final
persistence/performance/setup reviewer `ses_f40e6a10fffe3u30iD5Iya0dxa` independently
rechecked the corrected tree and evidence and **APPROVED with no findings**. Follow-up
coverage proves migration no-overwrite and retired-role reassignment denial. They did not
edit files or rerun suites. The only noted residual gap is no deliberately concurrent
assignment-versus-retirement PostgreSQL interleaving test; lock wiring and sequential
PostgreSQL behavior were inspected and no concrete defect was found. Only documentation
changed after their final approvals.

## Superseded Intermediate Cutover Evidence

Before the 2026-09-20 redesign, the resource-oriented native-voter/runtime-role cutover
was implemented, verified and freshly reviewed but not user accepted. Its evidence is
retained accurately as historical regression provenance only:

| Historical stage | Result |
| --- | --- |
| Setup | Passed through `Version20260917010000` with the then-current resource defaults/binding. |
| Full check | `run-KbeoQldw`: **1373 / 7762**, Deptrac **2477 / 0 / 0**. |
| Full E2E | `run-lN603myX`: **253 / 7392**; it preceded the localized DISTINCT-before-LIMIT correction. |
| Fresh consumer | `/tmp/opencode/donmario-setup-tYJRPVj1/`, embedded **253 / 7390** at `application/var/test-runs/run-pFf4F7aS/`; it preceded that correction. |
| Focused post-correction unit | **29 / 225**. |
| Focused post-correction PostgreSQL | **3 / 172**. |

Historical authorization/compiler/security reviewer
`ses_f4e681901ffe9Y8I3QBqtD1tEl` approved that intermediate design with no findings.
Historical persistence/setup/SQL reviewer `ses_f4e6818d1ffetu7BM3w4dZFq3c`
found the resource-branch limit-before-deduplication defect, then approved its correction
with no remaining concrete finding or material gap. Those reviews do not approve the
current global/ownership redesign.

The earlier pre-rework Task 10 evidence remains unchanged in
[Task 10's original record](10-task-tracking.md): check `run-Jzd2z00j`
(**1467 / 8917**), E2E `run-kLIovsdQ` (**252 / 7346**) and consumer
`/tmp/opencode/donmario-setup-zfkkNeft/` with embedded **252 / 7350**. It also does not
verify the current redesign.

Primary references:

- [Symfony 8.1 voters and decision strategies](https://symfony.com/doc/8.1/security/voters.html)
- [NIST RBAC](https://csrc.nist.gov/projects/role-based-access-control/faqs)
- [NIST ABAC](https://csrc.nist.gov/pubs/sp/800/162/upd2/final)
