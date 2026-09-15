# Subtask 8 — DTO collections and Authorizing model/management

## Approval and current checkpoint

On 2026-09-14 the user approved trusted operator-console administration, requested
global **and resource-scoped roles**, and explicitly chose Application DTO collections
and batch capabilities from the start for reusable applications. After the revised
design, the user approved **“proceed”**, followed by **“continue”**.

The approved sequence has two separately verified/reviewed/accepted checkpoints:

1. **8a — Application DTO collections: IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15.**
2. **8b — Authorizing model/management: APPROVED FOR IMPLEMENTATION on 2026-09-15; not yet implemented.**

### User acceptance and authorization — 2026-09-15

In response to the request to accept 8a and proceed to 8b, the user said exactly:

> commit, push and proceed

This records **8a USER ACCEPTED on 2026-09-15**, explicit authorization to commit
and push the verified 8a checkpoint, and approval to begin implementing the agreed
8b design. Main owns the Git workflow and will begin 8b after that commit/push.
This authorization is limited to the verified 8a checkpoint; future commits/pushes
require explicit authorization. 8b is not yet implemented and still requires its
own verification, fresh independent review and user acceptance.

8a is now the latest accepted checkpoint. Its verification and implementation reviews
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
on 2026-09-15; 8b was approved for implementation and has not yet been implemented.**

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

## Design review

Fresh independent design reviewer `ses_f61110bc0ffe4MeaUSNrTOpJyq` found no blocking
inconsistency and agreed with the 8a/8b split. Clarifications incorporated: Input is
not a top-level result; metadata checks cover intermediate DTO cascades; idempotency
uses the exact natural conflict target; performance checks include populated access
paths, not just SQL counts. This was read-only design review, not implementation review.
