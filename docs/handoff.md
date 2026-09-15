# Session handoff — Subtask 8a user accepted; 8b approved for implementation

Date: **2026-09-15**

Repository: `/var/home/adam/Projects/symfony-donmario-template`

## Status and next gate

**Current [Subtask 8a — Application DTO collections](tasks/08-authorizing.md) is
IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15.** The user approved
“proceed”, followed by “continue”, for the split 8a/8b design. Final setup, check,
actual PostgreSQL E2E and fresh-consumer verification passed. Both fresh independent
implementation reviewers approved with no findings and did not run suites.
Earlier fixture YAML/static issues are resolved. Verification and reviews completed
on **2026-09-14**; exact 8a evidence is below.
**8b Authorizing model/management is approved for implementation, not yet implemented.**
On **2026-09-15**, responding to the request to accept 8a and proceed to 8b, the user said exactly:

> commit, push and proceed

This accepts 8a, explicitly authorizes commit/push of the verified 8a checkpoint only,
and approves beginning 8b. Main owns task 8's record and the Git workflow, and will
begin 8b after that commit/push. Future commits/pushes require explicit authorization.
8a is the latest accepted checkpoint; Subtask 7's accepted evidence below is historical.

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
2. `docs/tasks/08-authorizing.md` — approved 8a/8b design and main-owned active task record;
   reconcile its progress with subsequent user/main updates.
   `docs/tasks/07-jwt-authentication.md` — accepted design, verification/review evidence
   and user acceptance recorded on 2026-09-14.
   `docs/tasks/06-web-authentication.md` — approved brief/correction, implementation,
   current verification status and pre-correction historical evidence/reviews;
   user acceptance recorded on 2026-09-13, including the registration correction.
   `docs/tasks/05b-native-event-bus.md` retains the accepted event checkpoint.
3. Composer/Flex manifests and locks; inspect current Git status before editing.

The working tree was **clean at the start of 8a implementation**, at existing commit
`c29a3f9`. The earlier uncommitted Subtask 6/7 handoff description is historical.
Preserve the new intended 8a implementation, tooling, tests and documentation edits.
Inspect current changes before editing. The user's 2026-09-15 request explicitly
authorizes main to commit/push the verified 8a checkpoint only, then begin approved 8b
implementation. It does not authorize future commits/pushes.
Preserve the instruction to use subagents for independent work.

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
[task record](tasks/08-authorizing.md). **8a was USER ACCEPTED on 2026-09-15;
8b is approved for implementation and remains unimplemented.**

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
  The web firewall excludes `/api`; business authorization is not implemented.

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
  authentication there. No business API/Authorizing is implemented.
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
Preserve local credentials, volumes and applied migrations. Evidence directories
may contain private settings; share only redacted logs. Tests must use isolated data.

## Fresh-session prompt

> Read `docs/handoff.md`, `AGENTS.md`, `README.md`, `docs/architecture.md`,
> `docs/roadmap.md` and main-owned `docs/tasks/08-authorizing.md`; inspect `composer.json`,
> `composer.lock`, `symfony.lock` and current changes. The tree was clean at existing
> `c29a3f9` before the new 8a edits; preserve them. On 2026-09-15 the user replied exactly
> “commit, push and proceed” to the request to accept 8a and proceed to 8b. This accepts
> 8a, authorizes commit/push of the verified 8a checkpoint only, and approves 8b implementation.
> Main owns the Git workflow and will begin 8b after that commit/push. Future commits/pushes
> need explicit authorization; reconcile subsequent main/user updates before acting.
> Subtask 7 remains accepted on 2026-09-14; its passing historical evidence does not
> verify 8a. The user approved “proceed”/“continue” for the split design. Subtask 8a
> is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15, the latest accepted checkpoint. Earlier fixture
> YAML/static issues are resolved; final setup/check/E2E/consumer verification passed
> and both fresh independent implementation reviewers approved with no findings on 2026-09-14.
> Reviewers did not run suites. Final check and consumer E2E cover the final compiler
> guard; standalone E2E preceded it, with runtime unchanged. Do not repeat passing
> suites merely to resume. Preserve native authentication/EventBus/CQRS and exact key boundaries.
> 8a adds synchronous CQRS/Input collections only; runtime needs no PHPDoc parser.
> 8b is approved for implementation, not yet implemented; it requires its own verification,
> fresh independent review and user acceptance after implementation.
