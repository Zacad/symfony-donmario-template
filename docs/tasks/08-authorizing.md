# Subtask 8 — DTO collections and Authorizing model/management

> **Superseded authorization behavior:** This accepted record and all evidence below are
> retained as historical provenance. Current behavior is defined by the
> [clean Authorizing/native-voter/Task-ownership rework](10-authorizing-rework.md): global
> subject assignments, runtime role/capability APIs and native voters on a fresh four-table
> baseline. The historical account/resource APIs and schema below are not current. Their
> accepted evidence remains provenance, but compatibility is not required: the clean model
> rewrites `Version20260915010000`, deletes the two intermediate authorization migrations
> and retains no resource/initial-binding schema. Clean-model implementation, verification
> and fresh review are complete; user acceptance is pending.

## Approval and current checkpoint

**Current checkpoint (2026-09-15): 8b is IMPLEMENTED, VERIFIED, REVIEWED and USER
ACCEPTED.** Following the full policy-only enforcement design, the user said
**“accept and proceed”**. Main recorded 8b acceptance and approval to implement
[Task 9](09-authorization-enforcement.md), now **IMPLEMENTED, VERIFIED, REVIEWED and
USER ACCEPTED on 2026-09-16**, including its enum correction. Its task record contains final setup/check/E2E/consumer
evidence and fresh independent approvals. The user's subsequent 2026-09-16
“commit and push changes” request authorizes this accepted 8b/9 delivery.
Earlier awaiting-acceptance
and Task 9 design-gate records below are historical and superseded by this checkpoint.

On 2026-09-14 the user approved trusted operator-console administration, requested
global **and resource-scoped roles**, and explicitly chose Application DTO collections
and batch capabilities from the start for reusable applications. After the revised
design, the user approved **“proceed”**, followed by **“continue”**.

The approved sequence has two separately verified/reviewed/accepted checkpoints:

1. **8a — Application DTO collections: IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15.**
2. **8b — Authorizing model/management: IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED (2026-09-15).**

### User acceptance and authorization — 2026-09-15

In response to the request to accept 8a and proceed to 8b, the user said exactly:

> commit, push and proceed

This records **8a USER ACCEPTED on 2026-09-15**, explicit authorization to commit
and push the verified 8a checkpoint, and approval to begin implementing the agreed
8b design. Main committed and pushed 8a as `367fdfe` to `origin/main`, then began 8b.
This authorization is limited to the verified 8a checkpoint; future commits/pushes
require explicit authorization. 8b implementation, verification and fresh independent
review were complete at that handoff; acceptance subsequently followed as recorded above.

8a was then the latest accepted checkpoint. Its verification and implementation reviews
remain dated **2026-09-14**; Subtask 7's accepted evidence remains historical.
The working tree was clean when 8a implementation resumed (latest existing commit
`c29a3f9`); earlier handoff statements about uncommitted authentication work are historical.

## Approved 8a design

- Extend exact public `Application/<UseCase>/<Name>{Command,Query,Result}` data with
  nested **Input** DTOs. Input is non-service public data, not dispatchable and not
  an allowed top-level handler result. Domain cannot consume it.
- Final readonly DTOs retain public typed promoted properties and empty constructors.
  Native `array` properties declare constructor `@param list<T> $name`; optional
  promoted `@var` must agree. Lists contain non-null scalar values, UUIDs, immutable
  dates, concrete public CQRS/Input DTOs or backed data enums. Empty `[]` is the only
  additional default. Maps, untyped/mixed arrays, item unions, nested generic lists,
  alternative type aliases/templates and recursive collection-bearing graphs fail.
  Named nested DTOs may have independently bounded collections.
- Parse PHPDoc with the explicitly required development `phpstan/phpdoc-parser`;
  resolve namespaces/import aliases and verify doc-only public-data dependencies
  without executing application source. Runtime has no PHPDoc-parser dependency.
- Use native Symfony 8.1 YAML `Type(list)`, finite `Count(max)`, `All(NotNull, Type)`
  and property-level `Valid`. Validate all ordinary nested DTO edges leading to a
  collection too. The development check compares parsed contracts against loaded
  Default-group validation metadata, including mapping registration.
- Native input validation remains before command transactions. New result-validation
  middleware runs immediately before handling and validates the returned DTO on
  stack unwind, inside invocation scope and the command-owned transaction. Invalid
  output is a fixed internal failure, never a client-input error. Caught nested
  output failures still poison the root transaction. Scalar/null/value/enum/void
  results retain their existing contracts; collection outputs use Result envelopes.
- Update source, container and CQRS classifications together. Event collection/wire
  support is outside this synchronous CQRS extension; exact principal/API exceptions
  retain their boundaries.

### Security and performance

Public DTOs are data, not capabilities. Runtime validation checks a value graph at
one point in time; readonly arrays are shallow, not deep-frozen snapshots. PHP array
element references and mutable subclass state are not universally prevented. Trusted
in-process code must construct ordinary owned lists of supported data.

Use-case limits bound accepted collections, not all possible allocation/traversal
cost. Native `Valid` on a list may traverse even when another constraint fails.
Future external adapters must bound transport bytes and item counts before constructing
large graphs. Validation mappings should be cheap and database-independent. No
automatic arbitrary collection serialization or polymorphic round-trip is promised.
Result validation happens before commit; error messages omit result/violation payloads,
but the existing invocation/exception objects are not guaranteed memory erasure.

### 8a acceptance and verification

1. Source positives/negatives for typed scalar/value/DTO lists, name resolution,
   Input placement, service exclusions, malformed declarations, defaults and cycles.
2. Loaded native metadata positives/negatives for shapes, item types/nulls, finite
   bounds, groups, registered mappings and every required nested cascade.
3. Compiled buses reject invalid list/nested input before handler/transaction work;
   named collection results validate before returning.
4. Actual PostgreSQL writes roll back after invalid command results, including
   caught nested command/query failures. Independent connections observe no early
   commit; successful recovery and existing scalar/value/void paths work.
5. Existing authentication, native events, source/DI/schema and consumer isolation
   regressions pass. Disposable collection fixtures add no production business module.

Required commands: `./bin/dev setup`, `./bin/dev check`, `./bin/dev test`,
`TMPDIR=/tmp/opencode ./bin/dev verify-setup`. Fresh independent implementation review
and user acceptance follow verification. The evidence below is specific to 8a.

### Completed implementation and verification — 2026-09-14

`phpstan/phpdoc-parser` **2.3.5** is now an explicit direct development requirement.
`./bin/dev composer require --dev 'phpstan/phpdoc-parser:^2.3' --no-interaction --no-scripts`
changed no package versions and reported no advisories. Source tooling lives in
`tools/Architecture/Collection*`; `php tools/collection-validation.php` is part of
`./bin/dev check`. Runtime result validation is the exact
`Platform/Messaging/ResultValidationMiddleware` in both synchronous buses.

| Command | Result and evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys, locked dependencies, latest migration; app/database healthy. |
| `./bin/dev check` | **PASS**: **656 architecture tests / 3619 assertions + 166 unit tests / 1112 assertions = 822 tests / 4731 assertions**. Deptrac **1266 allowed / 0 violations / 0 uncovered**. Metadata audit, audit/static/style/lint/shell and both native-event fixture modes pass. `var/test-runs/run-vjLRhO18/`. |
| `./bin/dev test` | **PASS**: **202 tests / 4673 assertions**, all **39 PHPUnit phases**; collection PostgreSQL journey **1 test / 82 assertions**. `var/test-runs/run-JcI8AkkQ/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-0X5cqk2l/`; embedded full E2E **202 tests / 4676 assertions**, all **39 phases**, at `application/var/test-runs/run-pGssVhBz/`. Includes the collection journey **1 test / 82 assertions**, independent consumer identity/authentication/keys, persistence, dev/test isolation and cleanup. |

The standalone E2E preceded a final compiler-only tightening: collection-bearing
Command/Query handler results, including transitive wrapper fields and union members,
must use a Result envelope. Runtime middleware did not change. The final full check
covers five new compilation negatives and existing noncollection return positives;
the subsequent fresh consumer boot/setup and full embedded E2E cover the final code.
Different async polling iteration counts account for the three assertion difference.

The collection fixture verifies fourteen malformed inputs without starting a DB
transaction; input data is non-service; valid scalar/UUID/date/enum lists and nested
DTO fields work. Actual immediate SQL and scheduled Task writes roll back on invalid
root output and caught nested command/query output errors. Instrumented transaction
IDs prove nested ownership; an independent connection sees no precommit writes;
successful roots flush once and same-process recovery succeeds. Fixed output errors
have no attached violations or previous validator exceptions. Fixture schema is
disposable isolated test infrastructure and is removed before later schema/persistence
checks. No Authorizing implementation has been introduced.

Early failed attempts: a direct Composer PHPUnit invocation was refused by the test
isolation guard without running tests; the first `check` exposed positional `All`
fixture YAML incompatible with Symfony 8.1, fixed using explicit `constraints`.
PHPStan issues and final fixture union/style diagnostics were corrected. These failed
runs are not final evidence; the final check above includes every required phase.

### Fresh independent implementation reviews — 2026-09-14

- **Source/metadata reviewer `ses_f5e99ad9affeSqojFsFsqYat0w`: APPROVED.** No concrete
  blocking findings or materially missing coverage. Inspected PHPDoc AST/import
  resolution, permitted item dependencies, transitive cascade/cycle checks, native
  Default metadata, Input service classification and development-only parser boundary.
- **Runtime/evidence reviewer `ses_f5e99ad4cffe6CQx3YoBgJaVN8`: APPROVED.** No concrete
  findings. Inspected exact middleware/Validator wiring, precommit ordering, caught
  nested rollback-only behavior, fixed diagnostics, transitive result-envelope guard,
  actual SQL/observer/transaction-ID fixture evidence and test cleanup/isolation.

Both reviewers inspected code and retained evidence and **did not rerun suites**.
No code changes followed their reviews; only completion documentation was updated.
Final container inspection shows development app/database healthy, worker stopped,
and no remaining verification-consumer containers. **8a was subsequently USER ACCEPTED
on 2026-09-15; 8b was approved for implementation.** The completed 8b evidence and
reviews appear below; these earlier 8a results do not establish 8b verification.

## Approved 8b design direction

Authorizing owns code-defined flat permission/role bundles and four mapped tables:
`authorizing_global_role_assignment`, `authorizing_resource_role_assignment`,
`authorizing_global_permission_grant`, `authorizing_resource_permission_grant`.
Each has a UUID key, natural assignment uniqueness and account/cursor indexes.
Account/resource references are opaque: no foreign module FK, ORM association or SQL.

All applicable sources are additive. Exact resources are `(type, UUID)`; global
sources cover the permission's declared type. Resource-scoped roles cannot confer
global-only permissions. Last-source removal affects subsequent checks after commit;
already-authorized work may finish. JWT/session roles are not permission authority.

Initial catalogue: `task_tracking.creator` bundles `task_tracking.task.create`
(global-only), `task_tracking.reader` bundles `.view` (global/task),
`task_tracking.editor` bundles `.view` and `.complete` (global/task), and
`authorizing.administrator` bundles `authorizing.manage` (global-only, enforcement
in task 9). Resource type: `task_tracking.task`. Catalogue changes are reviewed code
changes; retired keys remain inspectable/removable and reuse needs deliberate cleanup.

- `ChangeAccountAssignmentsCommand`: 1–100 distinct role/grant changes for one
  account; atomic add/remove, explicit scope, idempotent rows-actually-changed counts.
  Reject duplicate/conflicting natural keys. Parameterized set inserts use the exact
  natural conflict target; exact deletes remove only that source. A transaction-scoped
  account advisory lock precedes DML, at most eight write statements. No repository
  flush/commit/retry; locks last until the root finishes.
- `EvaluatePermissionsQuery`: 1–100 checks, potentially across accounts; one result
  per input in order. A new Authenticating-owned batch existence query returns only
  UUID/existence data. At most two business reads, including one Authorizing statement
  containing all four indexed sources. Missing account/unknown permission denies;
  malformed/incompatible scope fails the batch; outages never return allow/partial success.
- `ListAccountAssignmentsQuery`: page all four sources for one account, default 50,
  max 100; keyset cursor over account/source/immutable assignment UUID, branch and
  outer limits, one SQL query, no offset/total. Each page is a current snapshot;
  concurrent changes can affect later pages and UUIDv7 is not commit ordering.
- Additions require an observed existing account; removal-only/list supports orphan
  cleanup. Existence checks are snapshots, not referential integrity. Authorizing
  checks resource type/UUID, while the owning module establishes resource existence.
  This permits later unflushed Task creation plus nested initial-access assignment.
  Combined unflushed account provisioning/assignment is not yet supported.
- Trusted deployment shell/container access is operator administration authority.
  Single-item console commands adapt the batch use cases; structured batch JSON uses
  stdin, max 64 KiB, depth 16, exact fields, max 100 items, fixed errors. No caller
  privilege flag. Application-user administration/entry-point enforcement is task 9.

8b acceptance covers actual CLI management, all-source scope/union/revocation matrices,
100-item query budgets and populated query plans, unique indexes/concurrent mutations,
rollback/recovery, orphan/retired-key cleanup, keyset pagination, atomic initial owner
access and persistence/isolation. It requires its own full evidence and fresh review.

## 8b implementation and verification — 2026-09-15

Authorizing is installed with its Domain catalogue, four mapped assignment entities,
Domain repository port and set-based Doctrine adapter, three public batch/page APIs,
native YAML validation, and eight trusted operator console commands. Authenticating's
new `CheckAccountExistenceQuery` returns only UUID/existence records through QueryBus;
its owning repository performs a single parameterized read. No package/lock updates
were needed for 8b. The applied module migration is `Version20260915010000`.

The authority and consistency boundaries above remain explicit: CLI operator authority
comes from deployment shell/container access. Application-user enforcement belongs to
Task 9. A global check of a resource permission asks for all resources of its declared
type and considers only global sources. Resource checks include exact scoped sources.
Role additions must be compatible with the whole bundle. Account existence is read
before the account advisory lock and is a snapshot, not a referential guarantee.

| Command | Final result and evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; retained RSA3072 keys/dependencies, applied Authorizing migration, app/database healthy. |
| `./bin/dev check` | **PASS**: **656 architecture tests / 3619 assertions + 449 unit tests / 2657 assertions = 1105 tests / 6276 assertions**. Deptrac **1679 allowed / 0 violations / 0 uncovered**. Native collection metadata, audit, static, style, lint, shell and native-event fixtures passed. `var/test-runs/run-5YVJm6fG/`. |
| `./bin/dev test` | **PASS**: **219 tests / 5581 assertions**, all **42 PHPUnit phases**. Main Authorizing journey **15 tests / 848 assertions**, outage **1 / 8**, recovery **1 / 39**. `var/test-runs/run-mAZqm44t/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-taLhYutC/`; embedded **219 tests / 5581 assertions**, all **42 phases**, at `application/var/test-runs/run-chCqGwQF/`. All four consumer assignment UUIDs/rows and eight ordered allow/deny decisions survive repeat setup, app/database recreation and embedded isolated tests; `authorizing.log` records five successful checkpoints. Existing authentication/key/cookie/consumer isolation and cleanup pass. |

### Observable behavior and performance evidence

- Each source independently allows, all sources union, and removal of the last source
  denies. Wrong accounts/resources remain isolated; known incompatible scope rejects;
  unknown permission/missing accounts deny. Orphan/retired rows remain listable/removable.
- Native bus list bounds reject malformed/oversized inputs before SQL. Actual CLI tests
  cover all commands, strict scopes and bounded stdin/cursors. Invalid input exits 2,
  operation failure 1, and deny remains a successful exit 0 with `allowed: false`.
- Real PostgreSQL uniqueness/non-null/index/FK inspection, late DML failure rollback,
  and root/nested transaction tests pass. An unflushed Task can receive explicit nested
  initial access; failure of either side or a caught nested failure prevents commit.
- Native CLI processes demonstrably wait on the same account advisory lock while a
  different account progresses. Duplicate grants produce one change; ordered opposing
  changes follow transaction lock order rather than a special revoke-wins policy.
  Lock timeout rolls back the root and subsequent operations recover.
- At **N=100**, decisions use exactly **two business reads**, including account
  existence; a 100-change write uses the bounded set-based path. Mixed source/add/remove
  batches exercise all eight DML groups. Each page executes one bounded query.
- Populated plans include **8,000 unrelated assignments** without forcing index use.
  The 100-decision plan returns 100 rows and uses role natural-key indexes (estimated
  cost **4229.93**). A limit-7 first page returns 8 lookahead rows with all four cursor
  indexes and five Limit nodes (cost **26.22**). Source-3 continuation uses only its
  cursor index, skips prior sources, and returns 8 rows (cost **8.23**). These are local
  fixture observations, not workload-independent plan/latency guarantees.

The first E2E run found a Domain exception classified as operational for a mismatched
cursor; `InvalidAuthorizationInput` now extends `DomainException`, preserving the
fixed invalid-input exit. PostgreSQL EXPLAIN numeric fields legitimately decode as
floats, so numeric equality assertions replaced wire-integer assumptions. The final
check/E2E/consumer evidence above covers both corrections. Earlier failed/partial runs
are not final evidence.

### Fresh independent 8b implementation reviews — 2026-09-15

- **Policy/CLI reviewer `ses_f5beb07f6ffely21CI4G0EXW7N`: APPROVED, no findings.**
  Inspected scope and additive policy, whole-role-bundle validation, owning account
  query boundary, public DTO validation, bounded console input/cursors, fixed errors,
  atomicity and catalogue customization. Confirmed relevant passing evidence.
- **Persistence/evidence reviewer `ses_f5beb0781ffe8vZ9yo2cnH90jq`: APPROVED, no findings.**
  Inspected entity/migration/index agreement, owned parameterized SQL, transaction
  advisory locks, exact conflict targets and counts, nested/unflushed Task atomicity,
  concurrency/timeouts, bounded keyset queries, actual N=100/populated plans and
  consumer assignment-identity persistence, secrecy and cleanup.

Both reviewers inspected code and retained evidence and **did not run suites**.
Only completion documentation changed between 8b review and acceptance. Final 8b
container inspection confirms development app/database healthy and the worker stopped.
**Historical status: 8b was ready for user acceptance.** The subsequent “accept and
proceed” accepted 8b and approved the full Task 9 policy-only implementation design.
Its work remained uncommitted until the explicit 2026-09-16 Git delivery request;
future commits/pushes require fresh authorization.
Retained 8b verification and review above are not Task 9 evidence.

## Design review

Fresh independent design reviewer `ses_f61110bc0ffe4MeaUSNrTOpJyq` found no blocking
inconsistency and agreed with the 8a/8b split. Clarifications incorporated: Input is
not a top-level result; metadata checks cover intermediate DTO cascades; idempotency
uses the exact natural conflict target; performance checks include populated access
paths, not just SQL counts. This was read-only design review, not implementation review.
