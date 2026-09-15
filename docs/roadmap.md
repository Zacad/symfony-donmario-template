# Approval-gated delivery

General design and Subtasks **1, 2, 3a, 3b, 4, 5b, 6 (including the registration
correction), 7 and 8a** have user acceptance. Historical
[Subtask 4](tasks/04-synchronous-events.md) and [Subtask 5](tasks/05-durable-events.md)
delivery designs are **superseded by approved [Subtask 5b](tasks/05b-native-event-bus.md)**.
Their records retain historical evidence; current behavior is documented in
[architecture](architecture.md#native-application-events-5b).

**The user accepted 5b on 2026-09-13.** Accepted
[Subtask 6](tasks/06-web-authentication.md) includes the approved 2026-09-13 correction
making registration responsible for password-policy validation and hashing. It is
implemented; no new design gate is needed for that correction. **Subtask 6 including
the correction is VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-13.**
Post-correction setup/check/test, consumer verification and fresh independent review
are complete. **Subtask 7 is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on
2026-09-14**, including `/api/me` through QueryBus. Full verification and two fresh
independent reviews passed on 2026-09-13. **Current Subtask 8a — Application DTO
collections is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15** under
the approved [8a/8b design](tasks/08-authorizing.md). Final verification passed on 2026-09-14;
both fresh independent implementation reviewers approved with no findings and did
not run suites; reviews completed on 2026-09-14. Earlier fixture YAML/static issues are resolved.
**8b model/management is approved for implementation, not yet implemented.**
The user's exact 2026-09-15 “commit, push and proceed” accepts 8a, explicitly authorizes
commit/push of the verified 8a checkpoint only, and approves beginning 8b. Main will
begin 8b after that commit/push; future commits/pushes require explicit authorization.
8a is the latest accepted checkpoint. Reconcile the dated
[session handoff](handoff.md) with the active task record and subsequent instructions.
Later subtasks require their own discovery, design and approval before edits.
Split a subtask further if discovery reveals that its scope is too broad.

| # | Subtask | Main acceptance journey |
| --- | --- | --- |
| 1 | Repository/runtime foundation | Fresh Docker setup, HTTP, PostgreSQL, checks and isolation |
| 2 | Module/persistence boundaries | Boot and migrations plus positive/negative architecture checks |
| 3a | Application public-data boundary alignment | Co-located DTOs/handlers, public-data/private-service rules and regressions |
| 3b | CQRS/transactions | Shared HTTP/CLI use cases, validation and rollback |
| 4 | Layered events (historical; delivery superseded by 5b) | Retained event categories, public-data placement and optional Domain recording |
| 5 | Durable events (historical; superseded by 5b) | See historical task record |
| 5b | Native EventBus (accepted) | Immediate sync producer-transaction commit/rollback; global Doctrine switch; one-row atomic enqueue; current handlers, native retries/partial success, idempotency, worker crash/outage recovery and consumer isolation |
| 6 | [Authenticating: web](tasks/06-web-authentication.md) (including correction: verified, reviewed and user accepted 2026-09-13) | Hidden CLI/stdin provisioning, registration-owned password-policy validation/hashing, native login/refresh and POST/CSRF logout, hash-only CAS upgrade commands, sessions/throttling, outage/recovery and consumer isolation |
| 7 | [Authenticating: JWT](tasks/07-jwt-authentication.md) (implemented, verified, reviewed and user accepted 2026-09-14) | Native JSON issuance, QueryBus-backed API Platform identity, stateless isolation, exact JWT lifecycle, key setup/rotation/recovery and negative cases |
| 8a | [Application DTO collections](tasks/08-authorizing.md) (implemented, verified, reviewed and user accepted 2026-09-15) | Typed CQRS/Input lists, native metadata audit, input rejection before transaction work and invalid-result rollback before commit, including caught nested failures |
| 8b | [Authorizing: model/management](tasks/08-authorizing.md) (approved for implementation 2026-09-15; not yet implemented) | Global/resource-scoped roles and direct grants, atomic batch management, bounded permission decisions and keyset listing through trusted operator CLI |
| 9 | Authorization enforcement | Entry-point enforcement, revocation and restricted administration |
| 10 | TaskTracking use cases/CLI | Create/list/complete, ownership and invariants |
| 11 | TaskTracking events | Completion activity through CQRS in both delivery modes |
| 12 | TaskTracking Twig | Real browser workflows with allowed/denied users |
| 13 | API Platform business adapters | JWT API workflows, validation, isolation and pagination |
| 14 | HTMX enhancement | Browser interaction and ordinary form fallback |
| 15 | Filesystem/Valkey cache | Provider switch and transaction-aware invalidation |
| 16 | Reusable initializer | New identity, rerun and interruption recovery |
| 17 | Distribution verification | Independent generated apps and demo-free initialization |

Task records include the approved design, security/performance review, exact
verification commands/results and independent review findings. Documentation and
checks evolve alongside implementation. Forge-independent commands are the current
CI interface; production deployment is a separate future scope.

Subtask 5b uses the same Application-only EventBus and ordinary listener attributes
in both modes. `EVENT_TRANSPORT_DSN` chooses `sync://` by default or
`doctrine://default` globally. Sync listeners share the producer transaction before
final flush; async listener commands own their usual roots. Required invariants use
explicit nested commands. Async consumers require module-owned idempotency; native
partial-success stamps do not provide exactly-once effects or global ordering.
See architecture for serializer/trust limits, queue compatibility and soft worker
limits, and [README](../README.md#switch-event-delivery-and-run-the-worker) for supported
shell exports and worker operations.

Subtask 6 keeps native Symfony login/refresh as an explicit module-local Domain-read
exception, with no `LoginCommand`; registration and conditional hash upgrades write
through CommandBus. `RegisterAccountCommand(email, password)` carries plaintext;
the handler normalizes email, validates `PasswordPolicy`, hashes through the Domain
`PasswordHasher` port and adds the Account, all inside the existing registration
transaction. `SymfonyPasswordHasher` delegates only to the native hasher. The CLI
handles bounded secure input, confirmation, optional terminal LF/CRLF framing and
fixed errors, dispatching raw email/password with no business validation or hasher.
Native Symfony computes a replacement hash before the separate hash-only
`UpgradePasswordHashCommand` transaction, which retains CAS protection. Plaintext
is not persisted, queued or logged, but public readonly command/envelope and
validation-exception objects can retain it in memory; unsetting a CLI local does
not guarantee erasure. No public credential query/result/event exists.
Its exact internal non-service principal exception does not
permit general `UserInterface` services. Domain remains Security-independent. Native
session GC has no hard TTL; dedicated filesystem throttling is single-host with
documented I/O/concurrency limits. `/api` is excluded from the web firewall.

Subtask 7 adds native JSON login and Lexik RS256/RSA3072 tokens with exact 900-second
lifetime, zero skew and project/environment issuer/audience. `/api/me` authenticates
with a live UUID credential lookup, then uses `GetAccountIdentityQuery` through
QueryBus and the Domain safe-identity lookup to return `GetAccountIdentityResult`:
the approved second indexed read. `/api/docs.json` is the exact public OpenAPI route.
Dedicated key storage, separate initialization evidence and stopped-user rotation
support at most one old public key with operator retirement within 900 seconds.
There are no API sessions, refresh tokens, disabled-account state or per-token
revocation; password/rehash/web logout do not revoke JWTs. See task 7 and README for
contracts, recovery and security/performance limits. Final `./bin/dev setup` passed
retaining RSA3072 keys, with dependencies unchanged, migration current and app/database
healthy. `./bin/dev check` passed **695 tests / 4385 assertions**, Deptrac **1246 allowed /
0 violations / 0 uncovered**, at `var/test-runs/run-VU1J5W0M/`. `./bin/dev test` passed
**201 tests / 4606 assertions**, all 38 phases, at `var/test-runs/run-Dk8kGEH0/`.
`TMPDIR=/tmp/opencode ./bin/dev verify-setup` passed in
`/tmp/opencode/donmario-setup-tr7xoQb4/`, with embedded **201 tests / 4607 assertions**,
all 38 phases, at `application/var/test-runs/run-KkpCT8uO/`. Fresh authentication
reviewer `ses_f63a1e74affeszKsYM4RJDMnZS` and runtime reviewer
`ses_f63a1e72bffePxCpnh11QTnL9Q` **APPROVED** after inspecting code/evidence; they did
not rerun suites. Verification/reviews completed on 2026-09-13; task 7 records exact
conclusions and **user acceptance on 2026-09-14**.
These are accepted Subtask 7 results, not 8a verification. Authorizing 8b is approved
for implementation and not yet implemented; business API adapters retain their later gate.

Subtask 8a adds exact use-case-local `*Input` public non-service data (neither
dispatchable nor a top-level result), native `array` plus constructor `@param list<T>`,
and collection Result envelopes. Compilation rejects collection-bearing Command/Query
return types through transitive DTO fields and union members too.
`tools/Architecture/Collection*` supplies source
contracts and loaded native Default-group metadata auditing;
`php tools/collection-validation.php` runs through `./bin/dev check`.
**phpstan/phpdoc-parser 2.3.5** is an explicit direct development dependency, with no
package-version updates or runtime parser requirement. Supported sibling native
constraints are `Type(list)`, finite `Count(max)`, `All` with explicit `NotNull` and
matching item `Type`, and property `Valid` for DTO items/intermediate collection
wrappers, not arbitrary equivalent constraint wrappers. Homogeneous nonnullable
items and named DTO nesting with `[]` defaults are allowed; maps, item unions,
nested generic lists and recursive collection-bearing graphs are rejected.
`ResultValidationMiddleware` validates output inside invocation/command transaction
scope before commit; native input validation remains before transaction work.
Invalid output uses a fixed internal failure and caught nested failures invalidate
the root. Events retain their existing contracts. Trusted shallow readonly data and
per-use-case limits are not universal traversal/deep-immutability guarantees.

8a's final setup/check/E2E/consumer verification and fresh independent implementation
reviews completed on **2026-09-14**; **8a was USER ACCEPTED on 2026-09-15**. The final check and full
consumer run cover the final code after compiler-only return-guard tightening;
standalone E2E preceded that change, with runtime unchanged. See the
[task record](tasks/08-authorizing.md) and
[handoff evidence](handoff.md#completed-8a-verification-and-review--2026-09-14).
8b implementation is approved and main will begin after the authorized 8a commit/push.
It requires its own complete evidence, review and acceptance checkpoint.

**Subtask 6 final post-correction evidence:** `./bin/dev setup` passed with dependencies/migration
unchanged and app/database healthy. `./bin/dev check` passed **610 tests / 3942 assertions**,
Deptrac **1031 allowed / 0 violations / 0 uncovered**, at `var/test-runs/run-eTQK6EAr/`.
`./bin/dev test` passed **137 tests / 1991 assertions**, all 21 PHPUnit phases, at
`var/test-runs/run-xaiXVV3r/`. `TMPDIR=/tmp/opencode ./bin/dev verify-setup` passed in
`/tmp/opencode/donmario-setup-fej1lSca/` with embedded **137 tests / 1990 assertions**
at `application/var/test-runs/run-1pN3Ocj1/`, all 21 invocations. Fresh independent
correction reviewer `ses_f64339484ffeQjNteclNnjAlvj` inspected code/evidence and
**APPROVED** with no concrete findings. The earlier two approvals cover the unmodified
native web-authentication scope; the correction's fresh review is now complete.

**Pre-correction historical evidence only:** `./bin/dev setup` passed with Authenticating migration
`Version20260913010000` current; `./bin/dev check` passed **588 tests / 3738 assertions**
and Deptrac **1033 allowed / 0 violations / 0 uncovered** after all tooling changes
(`var/test-runs/run-hsi0IoyH/`); `./bin/dev test` passed
**120 tests / 1744 assertions** (`var/test-runs/run-qeu9uWzb/`); fresh `verify-setup`
passed in `/tmp/opencode/donmario-setup-RBWCh0mQ/` with embedded **120 tests /
1745 assertions**. Earlier reviewer approvals cover that snapshot, not the latest
correction. See the task record for exact historical evidence and the completed
post-correction verification/review and user acceptance on 2026-09-13.
