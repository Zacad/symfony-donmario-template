# Working on this template

## Start here

**Latest accepted checkpoint:** [Subtask 7](docs/tasks/07-jwt-authentication.md) is
**IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-14**. Verification
and fresh independent reviews completed on 2026-09-13; exact evidence is below.
**Next fresh session: Subtask 8 — Authorizing: model/management DISCOVERY/DESIGN ONLY.**
Present a bounded design, acceptance criteria and explicit security/performance review;
obtain user approval before implementation. Subtask 8 has not started.

**Previous accepted checkpoint:** [Subtask 6](docs/tasks/06-web-authentication.md), including
the user's 2026-09-13 correction making registration responsible for password-policy
validation and hashing, is implemented. That correction is approved, with no new
design gate needed. **Subtask 6 including the correction is VERIFIED, REVIEWED and
USER ACCEPTED on 2026-09-13.** Post-correction setup passed with dependencies/migration
unchanged and app/database healthy. Check: **610 tests / 3942 assertions**, Deptrac
**1031 allowed / 0 violations / 0 uncovered**, `var/test-runs/run-eTQK6EAr/`.
E2E: **137 tests / 1991 assertions**, all 21 PHPUnit phases,
`var/test-runs/run-xaiXVV3r/`. Consumer `/tmp/opencode/donmario-setup-fej1lSca/`
passed with embedded **137 tests / 1990 assertions** at
`application/var/test-runs/run-1pN3Ocj1/`. Fresh independent correction reviewer
`ses_f64339484ffeQjNteclNnjAlvj` inspected code/evidence and **APPROVED** with no concrete
findings. The earlier two approvals cover the unmodified native web-authentication
scope. See the task record for exact current evidence and pre-correction history.
**5b remains accepted (2026-09-13).**

**Accepted [Subtask 7](docs/tasks/07-jwt-authentication.md) — IMPLEMENTED, VERIFIED,
REVIEWED and USER ACCEPTED on 2026-09-14.** The user approved “i accept, proceed”, including
the correction that `/api/me` uses QueryBus and a second Domain safe-identity lookup.
Final setup passed retaining RSA3072 keys, dependencies and current migration;
app/database healthy. Check: **695 tests / 4385 assertions**, Deptrac **1246 allowed /
0 violations / 0 uncovered**, `var/test-runs/run-VU1J5W0M/`. E2E: **201 tests / 4606
assertions**, all 38 phases, `var/test-runs/run-Dk8kGEH0/`. Consumer
`/tmp/opencode/donmario-setup-tr7xoQb4/` passed with embedded **201 tests / 4607 assertions**,
all 38 phases, at `application/var/test-runs/run-KkpCT8uO/`. Fresh independent reviewers
`ses_f63a1e74affeszKsYM4RJDMnZS` (authentication) and `ses_f63a1e72bffePxCpnh11QTnL9Q`
(runtime) **APPROVED** after inspecting code/evidence; they did not rerun suites.
See task 7 for exact findings/coverage and the acceptance quote. Continue with
Subtask 8 discovery/design only in the next fresh session. Preserve intended
uncommitted work; no commit/push authorization exists.

Use subagents and paralelize work when possible and not affect results. Treat using subagents as default way of work for providing faster results.

- On a fresh session, read `docs/handoff.md`; reconcile its dated status with the
  active task record and subsequent user instructions.
- Read `README.md`, `docs/architecture.md`, `docs/roadmap.md`, and the active task record.
- Inspect `composer.json`, `composer.lock`, and `symfony.lock` before assuming a feature is installed.
- Work only in this standalone repository. Inspect user changes before editing.
- Prefer idiomatic Symfony, KISS and YAGNI; make dependencies and module boundaries explicit.
- Use the Symfony documentation matching the installed **8.1** release.

## Mandatory task workflow

1. Discover the current code and requirements for one coherent subtask.
2. Present a design with acceptance criteria and explicit security/performance review.
3. Obtain user approval **before implementing** that subtask.
4. Implement and prove the agreed journey end to end using actual containers and PostgreSQL.
5. Request a **fresh independent subagent review** with only the task's relevant brief,
   changed files and evidence. Resolve findings and reverify affected behavior.
6. Report results and obtain user approval before starting the next subtask.

A blocked or unrun check means the task is incomplete. General design approval is
not permission to implement the whole roadmap. Git commits/pushes require an
explicit request; Symfony CLI scaffolding must use `--no-git`.

## Commands

Use `./bin/dev` for setup, Symfony console, Composer, checks and tests. Host PHP,
Composer and Symfony CLI are not prerequisites. All task evidence should include
the exact command, result and meaningful observable behavior.

- `./bin/dev check`: validation, audit, static analysis, lint and Symfony style.
- `./bin/dev test`: real HTTP/database E2E, including outage and recovery phases.
- `./bin/dev verify-setup`: fresh consumer checkout and development/test isolation.

Only the `verify-setup` consumer PTY/cookie helper additionally needs host Python 3.

Run appropriate checks once changes are ready; repeat for new changes or unresolved
failures. Tests must establish behavior, not merely repeat implementation details.
Never redirect destructive tests to development data or expose local secrets in
tool output, test artifacts, source control or image contexts.

## Code conventions

- Install applicable Symfony components through Composer/Flex and review recipes.
- Use attributes, autowiring, autoconfiguration, typed properties and constructor promotion.
- Keep controllers/adapters thin. Use Symfony primitives for security, validation,
  caching and messaging rather than custom frameworks.
- Use PHP CS Fixer's `@Symfony` rules and PHPStan at the configured level.
- Namespace business modules as `App\Module\<ResponsibilityEndingInIng>`.
- Follow the module contract/data-ownership rules in `docs/architecture.md`.
- Co-locate commands, queries, handlers, useful results and public events in `Application/<UseCase>`.
  Public data uses descriptive `*Command`, `*Query`, `*Result`, `*Event` names at that exact
  depth; handlers/helpers remain module-internal. DTOs are data, not services.
  Other modules use this public data API through buses. Domain cannot depend on
  Application DTOs or public events. Domain records internal `Domain/Event/*Event`;
  Application explicitly translates selected facts to public events. No current
  `Contract` path. Public events cannot carry command/query/result DTOs or internals.
  Concrete events directly extend their exact empty abstract readonly category in
  `Platform/Event`: `BaseEvent -> DomainEvent, ApplicationEvent, InfrastructureEvent`.
  No primitive state, behavior, event IDs or metadata; no broad Domain-to-Platform
  permission. Exclude all event data/primitives from services. Keep source, Deptrac
  and container classification aligned.
- Domain objects may opt into the exact `Platform/Event/Recording/RecordsDomainEvents`
  interface and `RecordsDomainEventsTrait`. These pure, non-service support types
  provide protected recording and public release of internal Domain events. Application
  explicitly selects facts to translate after calling the entity's release method;
  the generic DomainEvent collection must not be interpreted as all creation facts.
  Identity stays local; no universal BaseEntity/BaseAggregateRoot or optimistic
  version is introduced by this capability. Keep this permission Domain-only.
- Our internal `Infrastructure/Event` and private `Infrastructure/EventListener`
  are distinct from vendor adapters in `Infrastructure/Framework/<Library>/EventListener`.
  Our listeners accept exact public Application events and use only public data,
  approved values, the handler declaration and exact CommandBus/QueryBus helpers;
  they cannot inject repositories, handlers, ORM or raw buses, even from their module.
- Define repository interfaces in the owning module's Domain and Doctrine adapters
  in Infrastructure/Persistence. Inject Domain ports into application handlers;
  adapters compose EntityManager and do not flush/commit. Enforce inward dependencies.
- `Platform` contains narrowly scoped technical infrastructure, including the
  current health endpoints; it is not a shared business-model directory.

## Web authentication boundaries

- Native Symfony `form_login` and `UI/Http/Security/AccountUserProvider` read owning
  Domain credential snapshots directly for login and UUID refresh: an explicit
  authentication exception, with no `LoginCommand` or public credential query.
  Domain has no Security dependency. Registration dispatches plaintext
  `RegisterAccountCommand(email, password)`; its handler calls `EmailAddress::normalize`,
  `PasswordPolicy::validate`, then the Domain `PasswordHasher` port and adds the Account.
  `SymfonyPasswordHasher` delegates only to the native hasher. Registration validation,
  hashing and persistence run inside the existing CommandBus-owned transaction.
  Native Symfony computes a replacement hash before dispatching the hash-only
  `UpgradePasswordHashCommand(accountId, expectedPasswordHash, newPasswordHash)`.
  That separate command transaction uses conditional hash replacement (CAS) to prevent
  stale overwrites.
  Plaintext is not persisted, queued or logged, but the public readonly registration
  command, envelope and validation-exception objects can retain it in memory. Unsetting
  a CLI local is not guaranteed erasure. No public credential query/result/event exists.
- Exact internal runtime data
  `Authenticating/Infrastructure/Framework/Symfony/Security/AccountPrincipal` is
  excluded from services. Permit Symfony's diagnostic placeholder only; do not
  generalize this exemption to `UserInterface` implementations or principal injection.
  Sessions serialize a crc32c hash fingerprint, never the reusable hash/password.
- Email: ASCII, outer ASCII trim, lowercase, at most 254 bytes. Password: valid UTF-8,
  15 Unicode characters minimum, 4096 bytes maximum, spaces preserved, no NUL/line
  breaks. Provisioning uses hidden password/confirmation with no visible fallback or
  explicit bounded stdin mode. The CLI handles secure input, confirmation, one optional
  terminal LF/CRLF as transport framing and fixed errors; dispatch raw email/password
  with no CLI business validation or hasher. Keep passwords out of argv, environment, queries/logs;
  never dump sensitive command/request/passport/exception payloads.
- Native logout requires POST and CSRF. Invalid login CSRF preserves existing
  authentication; late password-migration failure explicitly clears token/session.
  Native files use `var/sessions/<env>` and cookie `dm_<PROJECT_ID>_<env>`: host-only,
  HttpOnly, SameSite=Lax, Secure=auto. Browser-session cookies/GC have no hard TTL.
- Native limiter: 5 failures/minute per normalized identifier/IP, 25/IP/5 minutes;
  dedicated `var/security/<env>` storage/locks and stable secret-based keys survive
  cache rebuilds. Single-host native I/O is not guaranteed fail-closed; concurrent
  attempts can race. Empty native flock files may be 0666 under 0700 directories.
  Lock files accumulate; never unlink them while authentication processes are active.
- Caddy bounds framed authentication POSTs at 16 KiB (413), rejects unframed bodies
  (411) and external `/index.php` aliases (404). Preserve those pre-PHP protections.
  Actual storage/log canaries verify exercised paths, not universal secrecy.
- The web firewall excludes `/api`. JWT is implemented under Subtask 7's approved
  design, verified, independently reviewed and user accepted on 2026-09-14. Business authorization is
  not implemented and retains its later approval gate.

## JWT authentication boundaries

- POST `/api/login` uses native `json_login` with no success handler. A fully
  authenticated thin controller issues via Lexik only after all password-migration
  listeners finish. Reuse the native provider/hasher and CAS command; no LoginCommand.
  Login/bearer firewalls are stateless, with no web-session fallback or API session
  creation/invalidation. Only exact `/api/docs.json` has public `security: false`;
  an invalid bearer header does not require authentication on this documentation route.
- GET `/api/me` must take the trusted principal UUID and call QueryBus with
  `Application/GetAccountIdentity/GetAccountIdentityQuery`. Its co-located handler
  calls Domain `AccountRepository::findIdentityById`, maps safe Domain `AccountIdentity`
  to `GetAccountIdentityResult(id, email)|null`, and the provider maps to
  `UI/Api/AccountIdentityResource`. This is a deliberate second indexed read after
  the bearer credential lookup, not a direct principal-email projection. No public
  credential query/result exists. Deletion before authentication is 401; deletion
  between authentication and the identity query is 404.
- Exact `Authenticating/UI/Api/AccountIdentityResource` is non-service UI transport
  data with narrow architecture/DI classification. Do not generalize it to all API
  resources, Application DTOs or query-projection exceptions. Domain/Application
  remain Security-independent; the provider stays a private service.
- Installed Lexik 3.2.0 / Lcobucci JWT 5.6.0 / API Platform Symfony 4.3.19. Native
  RS256/RSA3072, TTL 900, skew 0; exactly sub/iss/aud/iat/nbf/exp, UUID subject,
  project/environment issuer/audience, nbf=iat and exp-iat=900. Check signature and
  normalized claims before live account lookup. No wire-integer guarantee, email,
  password-derived claims or business permissions. Authorization header only, bounded
  to an 8 KiB token plus `Bearer ` prefix before parsing. Login requires bounded
  strict JSON within the existing framed 16 KiB Caddy protection; web/API share the
  native limiter. Preserve fixed errors/no-store and secrecy canaries.
- No refresh, disable state or per-token revocation. Password changes/rehashes/web
  logout do not revoke JWTs; client logout discards its copy. Replay lasts until
  expiry or signing-key trust removal. Same-origin memory-only browser contract;
  reload needs login, HTTPS outside loopback, no persistent browser token storage.
- `jwt_keys` is owner-only unencrypted local PEM storage, app/console read-only;
  runner reads only isolated TEST keys read-only; worker has no key mount. Separate
  `var/docker/jwt-initialized` metadata matches volume `.identity` for key-loss
  detection. No build/cache/request/worker key generation. Native Lexik RawKeyLoader
  receives the additional-public-key array lazily from `/app/var/jwt/verification.json`
  via its service argument, not a dynamic Lexik configuration-tree array.
- Use `./bin/dev jwt-keys initialize|validate|rotate|rotate-emergency|retire`.
  Switch operations require stopped key users. The helper atomically publishes a
  generation symlink and prunes obsolete generations; at most one old public key.
  Operator must retire old trust within 900 seconds of stopping old issuance,
  including downtime; no automatic retirement. Emergency rotation drops old trust.
  Follow README's interrupted-init recovery: preserve/restore incomplete state;
  explicit reset only for disposable first initialization. Repair key-volume and
  metadata ownership independently after UID/GID changes; never delete valuable
  signing keys as cache recovery.

## Messaging and transactions

- Application/UI use exact CommandBus/QueryBus helpers. Application alone may use
  `EventBus::dispatch(ApplicationEvent): void`. Raw Messenger services, envelopes,
  stamps and invocation state stay internal. Private listeners handle public events
  with ordinary `#[AsMessageHandler(bus: 'application.event.bus')]` registration.
- Dispatch requires healthy owned command-handler execution, never an enclosing
  query or ORM lifecycle callback. Stack-based Doctrine callback detection has
  bounded coverage, not an arbitrary callback sandbox.
- `EVENT_TRANSPORT_DSN` globally selects `sync://` (default) or `doctrine://default`.
  Sync listeners execute immediately inside the producer transaction **before final
  flush**; their commands join its root. Pending writes need not be SQL-visible.
  Event dispatch failure invalidates the root even if caught.
- Async dispatch inserts **one native event row** on the same default DBAL connection
  and producer transaction. Workers use current handlers, with no outer event
  transaction; each listener command owns its usual root. Earlier committed commands
  can survive later listener failure. Native HandledStamps retain partial handler
  success on retry; crashes/partial listeners still require module-owned idempotency
  and transactional uniqueness for every independently committed step. No global
  ordering or exactly-once external effects are promised.
- Required invariants use explicit nested commands. Retain ORM `wrapInTransaction`
  and context/rollback-only invalidation for caught failures. Handlers/repositories
  never flush/commit. Independent operations reset ORM/context; cleanup failure
  disables further runtime messaging.
- Native Symfony JSON serialization uses standard UuidNormalizer and microsecond
  DateTimeNormalizer configuration. UUID, immutable dates and known concrete nested
  events are tested; do not infer arbitrary object-union/polymorphic round-trips.
  Queue/database writers are trusted. Restrict stored payloads/backups, including
  original wire data that native malformed-message failures may retain.
- Diagnostics adapters emit fixed metadata without payload/exception dumps, retaining
  native retry classification and HandledStamps. Failed-message command subclasses
  redact presentation only: native operator retry can execute handlers inline.
  Ordinary retries use 1/2/4-second delays; exhausted/unrecoverable events remain
  in `events_failed` for explicit operator action.

## Event operations and boundaries

- Export `EVENT_TRANSPORT_DSN` in the invoking shell; Compose passes it to app/CLI,
  worker and runner. Tests select isolated modes explicitly. Do not add it to the
  strict settings/credentials file `var/docker/local.env`. `./bin/dev up` recreates
  the app when its environment changes. Run setup migrations before starting workers.
- Use `./bin/dev worker start|stop|status`; start refuses sync mode. Raw Symfony
  consumption silently skips synchronous receivers, so use the supported wrapper.
  Stop/start workers after source changes. Sequential workers reset between messages
  and recycle after soft 3600-second/128M/1000-message limits. The 300-second lease
  has no keepalive or hard handler deadline; idempotency must tolerate overlaps.
- Queues `events` and `events_failed` use only `public.platform_messaging_message`
  and the retained exact `Platform/Messaging/Resources/migrations` path/namespace.
  Preflight found legacy queues empty. Old rows are neither converted nor deleted;
  new workers ignore old opaque rows. Drain legacy rows with compatible old code.
  Drain native pending/in-flight/failed events before mode or incompatible DTO/handler
  changes, or explicitly preserve compatibility. No automatic upcaster exists.
- Use module-owned migration namespaces/paths, unique UTC timestamps and reviewed
  module-local SQL, plus the exact Platform Messaging exception. Setup applies pending
  migrations without resetting data. No broad Platform schema or DI exemption applies.
- Source/DI/schema checks retain module boundaries. The simplified CqrsPass validates
  application handler/helper wiring, event policy/routing/options and default Doctrine
  ownership; do not claim exhaustive vendor transport/serializer/retry graph validation,
  business idempotency or a runtime sandbox. See `docs/architecture.md` for exact limits.
  `tests/Fixtures/NativeEvents` is disposable verification infrastructure.

Historical [Subtask 4](docs/tasks/04-synchronous-events.md) and
[Subtask 5](docs/tasks/05-durable-events.md) delivery designs are **superseded by
Subtask 5b**. Subtask 6 including its registration correction is verified, freshly
independently reviewed and **USER ACCEPTED on 2026-09-13**. Subtask 7's approved
implementation, including the identity-query correction, is **IMPLEMENTED, VERIFIED,
REVIEWED and USER ACCEPTED on 2026-09-14**. Next fresh session: Subtask 8 — Authorizing:
model/management discovery/design only, with a bounded design, acceptance criteria
and explicit security/performance review before implementation approval.
