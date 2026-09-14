# Subtask 6 — Authenticating web

## Approval and status

The user approved implementation with **“proceed”** after discovery/design and
discussion of the login boundary. The user's **2026-09-13 registration correction
is approved and implemented; no new design approval gate is needed for it.**
**Subtask 6 including the correction is VERIFIED, REVIEWED and USER ACCEPTED on
2026-09-13.** Post-correction setup passed; check passed **610 tests / 3942 assertions**,
HTTP/PostgreSQL E2E **137 tests / 1991 assertions** and fresh-consumer embedded E2E
**137 tests / 1990 assertions**. Fresh independent correction reviewer
`ses_f64339484ffeQjNteclNnjAlvj` inspected code/evidence and **APPROVED** with no concrete
findings. Earlier two approvals cover the unmodified native web-authentication scope;
the corrected registration's fresh review is complete. Next fresh session: Subtask 7
— Authenticating JWT discovery/design only, with acceptance criteria and explicit
security/performance review; implementation requires user approval.
Subtask 5b remains accepted (2026-09-13). No later subtask has started. JWT and business
authorization retain their separate gates.

### User acceptance — 2026-09-13

The user accepted **Subtask 6 including the registration correction** with the exact message:

> i accept, we will continue next task in fresh session

The intended uncommitted implementation, tooling, tests and documentation are preserved.
No current commit/push authorization exists; the user did not request either.

The user's latest correction supersedes the earlier hash-only-registration decision:
password-policy validation and hashing are the registration command handler's
responsibility. Native password-upgrade commands remain hash-only. Native session
lifetime remains the selected design. The user accepted **native Symfony web login as an exception**
to the usual input → command → handler flow. There is no LoginCommand, forwarding
handler, custom authenticator or controller-driven firewall wrapper.

### Approved user correction — 2026-09-13

- `RegisterAccountCommand(email, password)` is a public readonly plaintext input DTO.
  `RegisterAccountHandler` calls `EmailAddress::normalize`, then
  `PasswordPolicy::validate`, then the Domain `PasswordHasher` port, constructs the
  Account and calls repository `add`. The existing CommandBus-owned transaction
  wraps this validation, native hashing and persistence.
- The CLI owns bounded secure input, hidden confirmation, one optional terminal
  LF/CRLF as transport framing, raw email/password dispatch and fixed errors. It
  injects no hasher and performs no business validation. `SymfonyPasswordHasher`
  delegates only to the native hasher; password-policy validation belongs in the handler.
- Native Symfony still verifies login credentials and computes a replacement hash
  before dispatching the separate hash-only `UpgradePasswordHashCommand`. Its
  transaction retains conditional replacement (CAS); the native login/read exception
  is unchanged.
- Plaintext is not persisted, queued or logged, but the command, envelope and
  validation-exception objects can retain it in memory. Unsetting the CLI local is
  not guaranteed erasure. There is no public credential query/result/event.

## Approved design

- Authenticating owns Account (UUID, canonical email, password hash), repository
  ports and the `public.authenticating_account` table, with module migration
  `Version20260913010000`.
- Email: validated ASCII, maximum 254 bytes, trimmed outer ASCII whitespace and
  lowercase; no provider-specific dot/plus transformations.
- Password: minimum 15 Unicode characters, maximum 4096 bytes, valid UTF-8; spaces
  preserved; NUL and line breaks rejected. Symfony's named native `auto` hasher is
  used consistently for provisioning and verification. No custom cryptography.
- CLI `app:account:provision EMAIL` uses hidden password/confirmation without visible
  fallback. Explicit `--password-stdin --no-interaction` supports automation; one
  optional terminal LF/CRLF is removed, with bounded input and no other trimming.
  Hidden-input failure aborts instead of exposing input. Passwords never enter
  argv/environment or URL queries. The CLI dispatches raw email/password with no
  business validation or hasher, and presents fixed errors.
- `Application/RegisterAccount/RegisterAccountCommand(email, password)` carries
  plaintext; the use case returns UUID. Its handler normalizes email with
  `EmailAddress::normalize`, validates `PasswordPolicy`, hashes through the Domain
  `PasswordHasher` port, constructs the Account and calls repository `add`.
  `SymfonyPasswordHasher` delegates only to the native hasher. The existing command
  boundary wraps validation/hashing/persistence, then flushes and commits.
  Database uniqueness prevents concurrent/case-variant duplicates and overwrites.
- Native `form_login`, session CSRF, throttling and password verification handle
  POST `/login`. GET `/login` renders the form. `/account` requires full authentication
  and shows only safe identity fields. POST `/logout` validates CSRF and invalidates
  the session. Login success targets `/account`; failure/logout target `/login`.
  Caller-controlled redirects are ignored. GET/HEAD cannot log out. Invalid login
  CSRF preserves an existing authenticated session; login success rotates it.
- `UI/Http/Security/AccountUserProvider` reads module-local Domain credential snapshots
  directly for login and UUID-based refresh. This is the explicit authentication
  exception, not a general business-adapter repository permission. Domain remains
  Security-independent. No public credential query/results or credential events.
- `Infrastructure/Framework/Symfony/Security/AccountPrincipal` is internal runtime
  data, excluded from services with an exact inventory/DI exception. Symfony's
  excluded/deferred `#[CurrentUser]` diagnostic placeholder is permitted, not an
  instantiable principal service or module injection. This is not a general
  `UserInterface` exemption. The principal holds no entity or service.
  Session serialization stores Symfony's documented crc32c
  password-hash fingerprint; the reusable password hash is absent. Changed hashes
  (including rehash) and deleted accounts invalidate older sessions on refresh.
- Native password migration detects/computes a replacement before the provider dispatches
  `UpgradePasswordHashCommand(accountId, expectedPasswordHash, newPasswordHash)`.
  This remains a hash-only command, with computation outside its separate transaction.
  Module-local conditional SQL updates (CAS) under the owned command transaction prevent
  stale overwrites. No repository flush/commit. The current principal changes only
  after successful persistence. Conflict/operational failure aborts the login with
  explicit token/session cleanup, because native migration occurs after token setup.
- Native file sessions live outside cache in `var/sessions/<environment>`. Cookie
  names are `dm_<PROJECT_ID>_<env>` (Compose passes PROJECT_ID as APP_INSTANCE_ID); host-only,
  path `/`, HttpOnly, SameSite=Lax, Secure=auto. Browser-session cookies and explicit
  GC settings (`gc_maxlifetime=86400`, probability 1/100) do not promise a hard
  idle/absolute timeout.
- Native `DefaultLoginRateLimiter`: local 5 failures/minute per normalized
  identifier/IP; global 25/IP/5 minutes,
  explicit filesystem storage and flock locks under `var/security/<environment>`.
  APP_SECRET keying and fixed namespaces preserve state through cache rebuilds.
  Native empty flock files can be 0666 beneath 0700 directories; data files remain
  private. Lock files accumulate and must not be unlinked while authentication
  processes are active.
- No self-registration endpoint, password-reset/disable administration, remember-me,
  MFA, JWT, OIDC or business permissions. The web firewall excludes `/api`.

## Security/performance review

Registration and hash-upgrade commands are sensitive public Application *types*, not
public HTTP endpoints. Registration plaintext is not persisted, queued or logged,
but its public readonly command and Messenger envelope contain it in memory;
validation-exception objects can retain that command/envelope too. Unsetting a CLI
local does not guarantee erasure of references or string storage. Keep all
command/request/passport/exception payloads out of diagnostics; sensitive-parameter
annotations do not make object dumps safe. No public credential query/result/event exists.
Fixed authentication errors, request limits, secret-canary checks and safe session
serialization cover exercised presentation paths. Actual storage/log canaries are
bounded evidence, not a universal secrecy guarantee. Diagnostics report fixed or
bounded resource categories rather than credential-bearing paths/payloads.
Generic unknown/wrong-password errors do not
promise constant-time account-existence concealment.

Authentication POST bodies are limited to 16 KiB, with bounded scalar fields before
the firewall. Container ingress experiments found FrankenPHP could swallow Caddy's
body-reader overflow error; ingress therefore rejects oversized Content-Length
before PHP with **413** and requires Content-Length framing (**411** for unframed/
streamed auth POSTs). External `/index.php` and `/index.php/*` aliases return **404**
before PHP's internal front-controller rewrite, preventing a guard bypass. These
are bounded refinements for ordinary browser forms; actual oversized/streamed HTTP
tests verify the ingress behavior.

Registration validation and hashing occur **inside the existing command transaction**,
so native hashing cost extends its duration. Native login verification and replacement
hash computation remain Symfony-owned, before the separate hash-only upgrade/CAS
command transaction. Indexed email/UUID credential reads
return detached snapshots, preventing stale EntityManager ownership across CQRS roots.
Native session locking serializes requests for one session. Limiter locks protect
counter operations, not all in-flight attempts: simultaneous attempts can exceed a
sequential threshold. Native filesystem cache storage is not guaranteed fail-closed
on I/O failures. This is a deliberately single-host baseline, not distributed or
edge DoS protection. No arbitrary forwarded-IP trust is enabled.

Home/liveness preserve database-independent behavior even with an auth cookie;
global templates must not accidentally trigger user refresh. HTTP/PostgreSQL/session
failure and recovery require real verification. Same-host ports do not isolate
cookies: independent consumer applications must coexist with different cookie names.

## Acceptance and required evidence

1. Actual CLI provisioning, hidden terminal/stdin handling, validation, uniqueness
   (including concurrency), salted persistence and absence of leaked credentials.
   Direct plaintext registration commands must enforce email/password policy before
   hashing and persistence inside the existing transaction; the CLI and hasher adapter
   must not own that policy. Verify failure/rollback and fixed diagnostics without
   dumping sensitive command/envelope/exception objects.
2. Native HTTP login, negative credentials, malformed/bounded forms, fixed redirects,
   login/logout CSRF and GET-logout rejection.
3. Session rotation, replay rejection, cookie attributes, principal refresh and
   rehash/CAS persistence; failed rehash leaves no authenticated session.
4. Throttle thresholds, normalized/fresh-cookie attempts, broader IP protection,
   real expiry and app/cache recreation persistence.
5. PostgreSQL outage/recovery, app/database recreation, development/test isolation,
   fresh consumer account/session preservation and two-instance cookie isolation.
6. Existing CQRS/native-event/migration/source/DI checks pass; fresh independent
   reviews resolve findings and affected behavior is reverified.

## Final post-correction verification — VERIFIED (2026-09-13)

Main completed the following verification against the corrected implementation.
These aggregate results are the current proof, following the earlier core targeted
pass of **64 tests / 487 assertions**.

| Exact command | Completed post-correction result / evidence |
| --- | --- |
| `./bin/dev setup` | **PASS**; dependencies and Authenticating migration `Version20260913010000` unchanged; actual app/database healthy. |
| `./bin/dev check` | **PASS**: **511 tests / 3211 assertions + 99 tests / 731 assertions = 610 tests / 3942 assertions**. Deptrac **1031 allowed / 0 violations / 0 uncovered**; audit, PHPStan, lint, style and both-mode fixtures passed. `var/test-runs/run-eTQK6EAr/`. |
| `./bin/dev test` | **PASS**: **137 tests / 1991 assertions**, all 21 PHPUnit phases. `var/test-runs/run-xaiXVV3r/`. Actual HTTP/PostgreSQL journeys include transaction-active hashing, compiled hasher-throw rollback and CLI log checks without exercised credential leaks. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASS**: `/tmp/opencode/donmario-setup-fej1lSca/`; embedded **137 tests / 1990 assertions**, all 21 invocations, at `application/var/test-runs/run-1pN3Ocj1/`. Actual PTY/stdin space preservation and confirmation-mismatch tests, repeat setup, account/session preservation, two-consumer isolation and cleanup passed. |

The corrected registration journey demonstrates handler-owned email/password-policy
validation before native hashing and Account persistence, hashing with an active
CommandBus-owned transaction, and rollback when the compiled hasher throws. CLI
diagnostics retain fixed errors without the exercised secrets in logs. Actual
PTY/stdin tests establish transport framing, preserved spaces and mismatch handling;
the CLI owns neither business policy nor hashing. Storage/log checks remain bounded
evidence for the exercised paths, as described in the security review.

### Fresh independent correction review — REVIEWED

Reviewer **`ses_f64339484ffeQjNteclNnjAlvj`** inspected the corrected code and completed
evidence and **APPROVED with no concrete findings**. The earlier two reviewer approvals
below cover the unmodified native web-authentication scope. The corrected registration
now has its own completed fresh independent review.

**Subtask 6 including the registration correction is USER ACCEPTED on 2026-09-13.**
Subtask 7 discovery/design is reserved for the next fresh session; no later subtask has started.

## Pre-correction historical verification (2026-09-13)

The following evidence applies to the earlier hash-only-registration snapshot,
**not the latest approved correction**. Preserve it as historical evidence only.

| Exact command | Historical completed result / evidence |
| --- | --- |
| `./bin/dev setup` | Passed against actual development containers/PostgreSQL; Authenticating migration `Version20260913010000` current. |
| `./bin/dev check` | Final **PASS** after all tooling changes: **511 tests / 3211 assertions + 77 tests / 527 assertions = 588 tests / 3738 assertions**; Deptrac **1033 allowed / 0 violations / 0 uncovered**. PHPStan, audit, style, lint and both-mode checks all pass. `var/test-runs/run-hsi0IoyH/`. |
| `./bin/dev test` | Passed: **120 tests / 1744 assertions**, actual HTTP/PostgreSQL, native auth/session/throttling, persistence, outage/recovery and existing CQRS/event journeys. `var/test-runs/run-qeu9uWzb/`. |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | **PASSED**: `/tmp/opencode/donmario-setup-RBWCh0mQ/`; embedded E2E **120 tests / 1745 assertions**, at `var/test-runs/run-o7vT29al/` within that consumer. Actual hidden PTY provisioning, account/session preservation, repeat setup, development/test and two-consumer cookie isolation. |

The historical final check includes that snapshot's tooling corrections. The full
HTTP/PostgreSQL and fresh-consumer runs above and both reviewer rechecks below
do not establish verification/review of the latest registration correction.

Verification inspected actual app-private native session, limiter and lock storage,
confirming the hash fingerprint and absence of the exercised password/hash canaries.
Each app/container generation is stopped first, then its raw logs, including shutdown
output, are collected and checked before recreation/removal and redacted for retained
presentation. An actual earlier-generation
canary fault test verified detection before that generation disappears. This checks
the exercised storage/log paths and source handling, not arbitrary future logging.

### Integration and verification corrections

- Initial integration/test failures were resolved before the completed evidence
  above. Real PTY hidden-input handling needed corrected newline handling; full
  consumer verification now exercises password and confirmation without echo.
- Python cookie extension lookups are case-sensitive; the helper now uses
  case-insensitive cookie-attribute comparisons so differing spelling cannot silently
  bypass attribute checks.
- Storage verification now matches native empty FlockStore files, including `+` in
  the base64 suffix: 0666 empty lock inodes are expected under owner-only 0700
  directories. This exception does not permit public session/limiter data.
- BrowserKit's real HTTP request path required development `symfony/mime` **8.1.6**.
  Browser tests send actual HTTP requests rather than injecting authenticated users.

### Pre-correction independent review — historical rechecks approved

| Reviewer | Findings resolved in implementation/tooling | Historical status |
| --- | --- | --- |
| Security `ses_f64d5006fffeSQrj560MwdaAVr` | Front-controller ingress bypass closed with external alias rejection before internal rewrite; actual oversized/streamed request evidence covers the guard. | **APPROVED** on recheck; no remaining concrete findings. |
| Runtime/tooling `ses_f64d50049ffelRAvdjNV20My7V` | Secret-bearing identity/equality assertion output replaced with boolean `hash_equals` assertions; each generation stopped, then raw logs including shutdown output collected/checked/redacted before recreation/removal, with an actual earlier-canary fault test; PTY cleanup uses process-group bounded TERM/KILL/reap with a self-test. | **APPROVED** on recheck; **all three P2 findings resolved**, no further findings. |

These reviewer approvals cover the unmodified native web-authentication scope.
The corrected registration's fresh approval is recorded above; **Subtask 6 including
the correction is USER ACCEPTED on 2026-09-13**.
All implementation and documentation changes are intended
uncommitted working-tree changes; no commit/push was requested in this session.

At the pre-correction checkpoint, main's actual `docker ps` showed the project's app
`dm-1462298262-app-1` and database `dm-1462298262-database-1` healthy, worker stopped
and no consumer remnants. Development was in sync mode. This is historical runtime
state; the completed post-correction setup also confirmed app/database health,
and fresh-consumer verification completed cleanup.

### Historical local hashing performance observation

Main ran the following exact command in the actual development PHP 8.5 container:

```sh
./bin/dev composer exec -- php -r 'require "vendor/autoload.php"; $hasher = new Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher(); $hashMs = $verifyMs = []; for ($i = 0; $i < 3; ++$i) { $password = bin2hex(random_bytes(40)); $start = hrtime(true); $hash = $hasher->hash($password); $hashMs[] = (hrtime(true) - $start) / 1000000; $start = hrtime(true); if (!$hasher->verify($hash, $password)) { exit(1); } $verifyMs[] = (hrtime(true) - $start) / 1000000; } printf("Native hashing: algorithm=%s cost=%d samples=3 hash_ms=%.1f..%.1f verify_ms=%.1f..%.1f; no credential output.\n", password_get_info($hash)["algoName"], password_get_info($hash)["options"]["cost"], min($hashMs), max($hashMs), min($verifyMs), max($verifyMs));'
```

Passed: native **bcrypt cost 13**, three random 80-byte passwords; hashing
**490.9–494.3 ms**, verification **487.8–490.7 ms**. Only timing/algorithm metadata was
printed, with no credential output. This is a modest local observation, not an SLA
or a concurrent-load benchmark. It does not measure the corrected registration
transaction, which now includes native hashing work.

## Dependency/recipe discovery

Container Composer installed SecurityBundle/Core/HTTP, RateLimiter and Lock **8.1.6**,
PasswordHasher/CSRF **8.1.0**, and development BrowserKit/DomCrawler **8.1.5** and
CssSelector/Mime **8.1.6**. Native HTTP browser tests use real requests, not `loginUser()`.
Security/Lock Flex recipes are reviewed and adapted to the module-owned configuration;
login/logout use stateful CSRF and dedicated explicit lock/storage wiring.

Only the `verify-setup` consumer terminal/cookie helper additionally requires host
Python 3 for PTY and cookie-jar handling. Normal setup requires no host PHP, Composer
or Python; PHP/Composer verification runs through `./bin/dev` in containers.
