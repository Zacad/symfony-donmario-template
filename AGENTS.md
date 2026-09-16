# Working on this template

## Start here

**Latest accepted checkpoint: [Task 9 — Policy-only authorization enforcement](docs/tasks/09-authorization-enforcement.md),
including the `ActorKind` correction, is IMPLEMENTED, VERIFIED, REVIEWED and USER
ACCEPTED on 2026-09-16.** Following additional fresh design/implementation reviewer
`ses_f5691c623ffeKmeUgmFSPqCWQ7` approval with no findings or material coverage gaps,
the user said exactly **“ok, i accept task, nest task will be continued in fresh session”**.
Only acceptance/handoff docs changed afterward. **Next session starts Task 10 —
TaskTracking use cases/CLI — discovery/design only; implementation needs separate
design approval.** On 2026-09-16 the user explicitly requested **“commit and push changes”**.
This delivery commit records the accepted 8b/9 work, enum correction and handoff.
Earlier uncommitted/no-Git-authorization notes below predate that request; future
commits/pushes require fresh authorization. Use Git history for the delivery hash.
Do not rerun passing suites merely to resume. See `docs/handoff.md` for the next gate.

**Completed correction (2026-09-16):** the user requested an enum for actor kind. `ActorKind` now
types `Actor::$kind` (`Anonymous`, `Account`, `Operator`, `Authentication`), with
matching non-service/source/DI/Deptrac classification. Post-correction setup passed;
check `var/test-runs/run-SSb9mtrr/` passed **1360 tests / 7959 assertions**, Deptrac
**1887 allowed / 0 violations / 0 uncovered**. E2E `var/test-runs/run-0BEEcpKm/` passed
**230 / 6306**, all **46 phases**. Consumer `/tmp/opencode/donmario-setup-M4B96yAf/`
passed with **230 / 6310** at `application/var/test-runs/run-uTaVmkbI/`, all 46 phases
and five authorization/HTTP checkpoints. Fresh correction reviewer
`ses_f574cd1afffet0VIFWAYaHvPMl` **APPROVED**, no findings, including completed consumer
evidence; no suites rerun. Task 9 including this correction is now user accepted.
The original Task 9 evidence below is historical. See
`docs/tasks/09-authorization-enforcement.md`. Git delivery is authorized as recorded above.

**Earlier accepted checkpoint: [8b Authorizing model/management](docs/tasks/08-authorizing.md)
is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15.** Following the
full policy-only enforcement design, the user said **“accept and proceed”**; main
recorded 8b acceptance and approval for [Task 9 implementation](docs/tasks/09-authorization-enforcement.md).
Task 9 is now **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED**.
Main owns its [task record](docs/tasks/09-authorization-enforcement.md). Original setup
passed retaining keys/dependencies/migration. Check `var/test-runs/run-1nmJjuRE/`:
**1335 tests / 7775 assertions**, Deptrac **1878 allowed / 0 violations / 0 uncovered**.
E2E `var/test-runs/run-K1eShLCh/`: **230 tests / 6306 assertions**, all **46 phases**.
Consumer `/tmp/opencode/donmario-setup-zCUNToYg/` passed with embedded **230 / 6308**,
all 46 phases at `application/var/test-runs/run-f78VOUau/`, and five stable assignment/
session-authorized/anonymous-denied HTTP checkpoints. Fresh reviewers
`ses_f59626304ffe7FfAL8d0URx8mX` and `ses_f596262c7ffelHpvG7S2VBGlix` approved without
findings, including completed consumer evidence. No suites were rerun by reviewers.
Full check preceded four E2E fixture-only changes with passing targeted PHPStan/style;
final standalone and consumer E2E cover them. Only docs changed after final reviews.
Earlier failed-check and awaiting-verification/8b-acceptance records are superseded.
Do not rerun passing suites merely to resume; Task 10 discovery/design is next.

8a remains accepted, verified/reviewed on 2026-09-14 and accepted on 2026-09-15;
see [8a evidence](docs/handoff.md#completed-8a-verification-and-review--2026-09-14).
The earlier “commit, push and proceed” authorized only 8a, pushed as `367fdfe` to
`origin/main` on 2026-09-15. **The subsequent 2026-09-16 request authorizes this 8b/9 delivery.**
Main owns Git. Final 8b setup/check/E2E/consumer verification included N=100 SQL
budgets and populated plans; both fresh reviewers approved with no findings and did
not rerun suites. Only docs changed between 8b review and acceptance. See
[8b evidence](docs/handoff.md#completed-8b-verification-and-review--2026-09-15).
Task 9 was accepted on 2026-09-16; continue with Task 10 discovery/design in a fresh session.

**Earlier accepted checkpoint:** [Subtask 6](docs/tasks/06-web-authentication.md), including
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
See task 7 for exact findings/coverage and the acceptance quote. These are historical
accepted results, not 8a evidence. 8a verification/review completed on 2026-09-14;
user acceptance followed on 2026-09-15.
The working tree was clean at existing `c29a3f9` before 8a implementation;
8a is now pushed as `367fdfe`. Earlier uncommitted authentication/8a descriptions
are historical. Preserve the accepted 8b/9 implementation and evidence recorded in this delivery.

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
- Co-locate commands, queries, handlers, useful results, nested inputs and public events in `Application/<UseCase>`.
  Public data uses descriptive `*Command`, `*Query`, `*Result`, `*Input`, `*Event` names at that exact
  depth; handlers/helpers remain module-internal. DTOs are data, not services.
  Other modules use this public data API through buses. Domain cannot depend on
  Application DTOs or public events. Domain records internal `Domain/Event/*Event`;
  Application explicitly translates selected facts to public events. No current
  `Contract` path. Public events cannot carry command/query/result/input DTOs or internals.
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

## Application DTO collections (8a: user accepted 2026-09-15)

- Input suffixes are reserved public non-service data at the exact use-case depth.
  Keep `ContractTypes`, source/Deptrac, container exclusions and CQRS classification
  aligned. Inputs cannot be dispatched or used as top-level handler results.
  Collection outputs use named Result envelopes. Compilation rejects collection-bearing
  Command/Query return types, including transitive DTO fields and union members.
  Events retain their collection-free
  payload and wire contracts; principal/API-resource exemptions remain exact.
- Final readonly DTOs use public typed promoted properties and empty constructors.
  Native nonnullable `array` requires constructor `@param list<T>`; optional promoted
  `@var` must agree. Homogeneous non-null scalar/UUID/immutable-date/concrete CQRS/Input
  DTO/backed data-enum items are allowed. Reject maps, nullable items, item unions,
  untyped arrays, nested generic lists, aliases/templates and recursive collection-bearing
  graphs. Named DTO nesting with independently bounded lists and `[]` defaults is allowed.
- `tools/Architecture/CollectionDocTypes` and `CollectionContracts` parse source
  PHPDoc, resolve namespaces/imports and check doc-only dependencies/cascade graphs
  without executing application source. **phpstan/phpdoc-parser 2.3.5** is an explicit
  direct development dependency; no package-version updates or runtime parser requirement.
- `CollectionValidationMetadata`/`CollectionValidationKernel` compare contracts with
  loaded native Default-group property metadata. `./bin/dev check` runs
  `php tools/collection-validation.php`. Require exact native sibling `Type(list)`,
  finite nonnegative integer `Count(max)`, `All` with explicit `NotNull` and matching
  item `Type`, and property `Valid` for DTO items and ordinary DTO edges leading to
  collections. Register YAML mappings explicitly and constrain item DTO fields.
  Arbitrary equivalent wrappers, class cascades and group-sequence overrides are not
  supported substitutes. README contains the native YAML/DTO example.
- Native input validation precedes command transaction work. Exact
  `Platform/Messaging/ResultValidationMiddleware` sits immediately before handling,
  validates DTO output on unwind inside invocation/transaction scope and before
  commit, and throws fixed internal `cqrs.result_validation: Handler returned invalid data.`
  without output/violation payloads. Caught nested output failures invalidate the root.
  Existing scalar/null/value/enum/void results retain their contracts.
- Trust in-process constructors to supply ordinary owned lists. Readonly arrays are
  shallow; references/mutable subclass state are not universally prevented. Bounds
  limit accepted data, not all allocation/traversal; native `Valid` can traverse after
  other failures. External adapters must bound bytes/items before DTO construction.
  Keep mappings cheap/database-independent; do not claim universal traversal, deep
  immutability, serialization or in-memory erasure guarantees.
- Final setup/check/test/consumer verification, including actual PostgreSQL invalid-result
  and caught-nested rollback/recovery, and both fresh independent implementation reviews
  are complete. Final check and consumer E2E cover the final compiler-only return guard;
  standalone E2E preceded it, with runtime unchanged. Do not repeat passing suites
  merely to resume. 8a was user accepted and pushed as `367fdfe` on 2026-09-15;
  8b is user accepted; Task 9 is verified/reviewed and user accepted on 2026-09-16.

## Policy-only enforcement (Task 9: user accepted 2026-09-16)

- Each command/query handler declares a co-located private final readonly policy
  with `#[AuthorizeWith(...)]`; its exact message plus immutable `PolicyContext`
  returns bool. Actor-dependent admission belongs only in policies. Permission
  calculation/catalogue validation, password/hash validation and Domain invariants
  remain use-case logic. No policy injections into handlers.
- Source/DI/Deptrac classifications align. Authorization middleware needs its exact
  private framework locator to resolve policies; no general module access to policy
  services or mutable execution context. Policies may use QueryBus and owning Domain
  read ports/state, not handlers, commands/events, ORM/SQL or outward adapters. These
  guardrails are not an arbitrary PHP/SQL sandbox; constructors remain side-effect-free.
- `PolicyContext(actor, supportRead, caller)` comes from infrastructure. Native fully
  authenticated HTTP identity supplies the account UUID, separate from DTO targets.
  `/api/me` is self-only. Exact adapters alone receive scoped helpers: provisioning
  uses operator `accounts`, authorization CLI `assignments`, existing Task create/show
  CLI `tasks` (no actor argument); native account provider alone uses account-bound
  `AuthenticationExecution` for hash upgrades. CLI/worker execution alone grants nothing.
- Foundation policies are exact: permission evaluation admits policy support reads
  or `assignments` operators; account existence admits support reads or direct callers
  `EvaluatePermissionsQuery`/`ChangeAccountAssignmentsCommand`. No general internal
  bypass; ordinary nested operations reauthorize. Policy resolution/execution cannot
  dispatch commands/events, including through nested bus calls.
- Input validation precedes admission; command policy runs inside the owned transaction,
  before result-validation/handling; queries gain no automatic transaction. Caught
  nested failures invalidate the root and prevent admission/commit even if the policy
  returns true. No actor changes within bus execution. Same-transaction checks do not
  serialize against revocation; policy and handler reads may overlap.
- Account actors need global create for Task Create, exact-resource view for Task Get,
  and global `authorizing.manage` for assignment change/list. Explicit corresponding
  operator scopes also admit. Dev/test Task HTTP uses web sessions and fixed denial
  JSON `{"error":"Access denied."}` (401 anonymous / 403 authenticated). No automatic
  Task grant, list filtering or durable service identity yet.

## Authorizing boundaries (8b: user accepted 2026-09-15)

- Trusted deployment shell/container access supplies operator authority for eight
  console commands, now using explicit operator `assignments` scope. There are no new
  HTTP/API management routes. Task 9's verified/reviewed policy-only implementation is user accepted.
- Authorizing owns the four mapped global/resource role-assignment and direct-grant
  tables and `Domain/AuthorizationCatalog`. Migration `Version20260915010000` was
  applied by passing setup; dependencies and locks are unchanged. See architecture
  for exact table names and README for catalogue customization and console contracts.
- Public bus APIs are `ChangeAccountAssignmentsCommand`, `EvaluatePermissionsQuery`
  and `ListAccountAssignmentsQuery`. Authenticating owns `CheckAccountExistenceQuery`,
  returning only UUID/existence data through QueryBus. No cross-module SQL/FK/association.
- Sources are additive. A global permission check asks for all resources of its
  declared type and uses only global sources; resource checks also include exact
  `(type, UUID)` sources. Known permissions with incompatible scope are invalid;
  missing accounts/unknown permission keys deny. No cache or JWT/session permission authority.
- Changes are 1–100 distinct natural keys for one account, atomic and idempotent,
  with rows-actually-changed counts. Validate additions against the whole role bundle's
  scope. Removal/listing allow retired keys and orphan cleanup. Existence is observed
  **before** the account advisory lock and is only a snapshot. The transaction-scoped
  lock lasts until the root ends; at most eight DML statements, no handler/repository
  flush, commit or retry. Resource existence belongs to its owning module; omitting
  resource-existence SQL permits nested initial access for an unflushed owned Task. Unflushed account
  registration plus assignment is unsupported.
- Evaluation uses at most two business SQL reads, including owning account existence;
  listing uses one bounded keyset query. Pages are 1–100/default 50, ordered by
  account/source/immutable assignment UUID, without totals or cross-page snapshots.
  Actual N=100 budgets and populated-plan verification passed; see final handoff
  evidence for observed access paths, without universal plan or latency guarantees.
- Console scope is explicit: `--global` is exclusive with the paired resource options.
  Batch JSON stdin is at most 64 KiB, depth 16 and 1–100 items, with exact fields;
  global items omit resource fields (explicit null is rejected). Opaque cursors are
  at most 512 characters, strictly decoded and bound to account/source/UUID; they
  are pagination data, not authorization grants. Exit 2 is invalid input, 1 operational
  failure, 0 success including deny. Preserve fixed errors and bounded parsing.

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
  design, verified, independently reviewed and user accepted on 2026-09-14. 8b model/
  management is user accepted; Task 9 policy enforcement is verified/reviewed,
  user accepted on 2026-09-16.

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
REVIEWED and USER ACCEPTED on 2026-09-14** and remains an accepted historical checkpoint.
Accepted Subtask 8a is **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15**.
Final 8a verification and both fresh independent implementation reviews completed on
2026-09-14 with no findings; 8a was pushed as `367fdfe` on 2026-09-15. Earlier accepted
8b is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15. Task 9's full
policy-only design was approved with “accept and proceed”; it is implemented, verified
and reviewed, with user acceptance on 2026-09-16. Main owns Task 9's record/final evidence.
8b/9 are included in this authorized delivery; future commits/pushes need authorization.
