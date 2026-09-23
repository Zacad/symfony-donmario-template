# Working on this template

## Start here

**Active [clean Authorizing model](docs/tasks/10-authorizing-rework.md) was approved
on 2026-09-22. Implementation, fresh setup/check/E2E/consumer verification and fresh
independent review are complete; user acceptance is pending.** This is a fresh-template-only redesign. Compatibility with any authorization
schema or rows produced by the unaccepted intermediate Task 10 reworks is explicitly not
required. Do not report the clean model accepted, begin Task 11, commit or push. Main owns
the active record; read it and `docs/handoff.md` first.

The clean baseline rewrites `Version20260915010000` and deletes authorization migrations
`Version20260917010000` and `Version20260920020000`. Authorizing owns exactly four tables:
`authorizing_role`, `authorizing_role_permission`, `authorizing_role_assignment` and
`authorizing_permission_grant`. Assignments use `subject_id` plus role/permission keys as
their natural identities. There are no resource grants, resource assignments, initial
bindings, scope/resource columns, `account_id` columns or synthetic assignment IDs.
Existing databases from the intermediate designs require disposal/recreation; do not add
compatibility migrations or fallback reads.

The approved model otherwise preserves native authorization: every handler has exactly
one `#[Authorize]`; restricted forms name a concrete same-module voter and actor-
unrestricted forms use only `#[Authorize(public: true)]`. Module-owned backed permission
enums feed `CqrsPass`; module voters and the compiler-selected Platform public voter use
the isolated private `AccessDecisionManager` with `UnanimousStrategy(false)`.
Permissions, roles and direct grants remain global. Authorizing remains generic and
subject-based, with no account lookup, Task imports, resource API or authorization YAML.
TaskTracking's voter combines global permission with immutable Task ownership; `tasks`
operators bypass account ownership. Preserve DBAL repositories, advisory-lock namespaces,
query budgets, role/capability APIs, credential-free tokens and actor/transaction/event
guarantees.

Authorizing Domain types are organized by Assignment, Capability and Role business
concepts. Only this module uses explicit technical suffixes such as `Entity`,
`ValueObject`, `Enum` and `Service`; do not carry that convention into another module
without a separate decision. Group by reason to change, never by technical type: there is
no `Domain/Mapping`, `Domain/Entity`, `Domain/ValueObject` or placeholder directory.
Persisted domain objects use `Entity`, immutable validated identity-free data use
`ValueObject`, closed backed vocabularies use `Enum`, stateless domain collaborators use
`Service`, and Domain persistence ports retain `Repository`. The suffix does not decide
whether an entity is rich or narrow, and technical suffixes do not create namespaces.
Cross-concept validators/exceptions may remain at the Domain root. `RoleEntity` owns creation, definition/revision and
retirement lifecycle. Assignment uses `AssignmentReferenceValueObject(kind, key)` for
stored rows and cursors, `AssignmentChangeValueObject(operation, reference)` and
`AssignmentChangeCountsValueObject(added, removed)`; there is no `ChangeSet`,
`StoredAssignment` or Domain cursor. Public mutation inputs expose only
`operation`/`kind`/`key`; assignment rows and cursors expose only `kind`/`key` (plus the
cursor's subject binding). Historical account/resource API and schema descriptions remain
provenance only, not current instructions.

**Pre-rework [Task 10 — TaskTracking use cases/CLI](docs/tasks/10-task-tracking.md) is
IMPLEMENTED, VERIFIED, REVIEWED, but NOT USER ACCEPTED; its authorization design is
superseded by the correction above.** The user
selected revocable owner access, all viewable tasks and unowned legacy/operator tasks,
then approved the complete design with **“proceed”**. Final setup passed; check
`run-Jzd2z00j`: **1467 tests / 8917 assertions**, Deptrac **2200 / 0 / 0**; E2E
`run-kLIovsdQ`: **252 / 7346**, all **53 phases**. Consumer
`/tmp/opencode/donmario-setup-zfkkNeft/` passed with embedded **252 / 7350**, all
53 phases at `application/var/test-runs/run-4SWsQ0go/`, plus five TaskTracking
persistence/isolation checkpoints. Fresh reviewers `ses_f55a7cd3effeWy1iCZ773BwaKm`
and `ses_f55a7cd2effeL2gpYyxGtTaSdL` approved without findings/coverage gaps and
did not rerun suites. Only docs changed afterward. This is regression evidence,
not verification of the active redesign. **Use the clean-model implementation and
verification gate above.** Do not rerun historical suites merely to resume. Task 10
changes are intended uncommitted work;
**no Task 10 commit/push is authorized**. The prior authorization delivered 8b/9 as
`270ba43`. Main owns the Task 10 record; see `docs/handoff.md`.

**Latest accepted checkpoint: [Task 9 — Policy-only authorization enforcement](docs/tasks/09-authorization-enforcement.md),
including the `ActorKind` correction, is IMPLEMENTED, VERIFIED, REVIEWED and USER
ACCEPTED on 2026-09-16.** Following additional fresh design/implementation reviewer
`ses_f5691c623ffeKmeUgmFSPqCWQ7` approval with no findings or material coverage gaps,
the user said exactly **“ok, i accept task, nest task will be continued in fresh session”**.
Only acceptance/handoff docs changed before delivery. The former next gate of Task 10
discovery/design is now superseded by the active checkpoint above. On 2026-09-16 the
user explicitly requested **“commit and push changes”** for the accepted 8b/9 delivery.
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
Do not rerun passing suites merely to resume; use the active checkpoint above.

8a remains accepted, verified/reviewed on 2026-09-14 and accepted on 2026-09-15;
see [8a evidence](docs/handoff.md#completed-8a-verification-and-review--2026-09-14).
The earlier “commit, push and proceed” authorized only 8a, pushed as `367fdfe` to
`origin/main` on 2026-09-15. **The subsequent 2026-09-16 request authorizes this 8b/9 delivery.**
Main owns Git. Final 8b setup/check/E2E/consumer verification included N=100 SQL
budgets and populated plans; both fresh reviewers approved with no findings and did
not rerun suites. Only docs changed between 8b review and acceptance. See
[8b evidence](docs/handoff.md#completed-8b-verification-and-review--2026-09-15).
Task 9 was accepted on 2026-09-16. Its operating model and the later verified intermediate
reworks are historical provenance; the clean model is implemented, verified and reviewed,
with user acceptance still required.

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

## Current Authorization And Task Boundaries

- Every command/query handler declares exactly one `#[Authorize]`. Restricted forms name
  a concrete final same-module voter; permission-bearing declarations use module-owned
  string-backed permission enums and stable labels, while contextual declarations omit
  the permission. Actor-unrestricted forms use only `#[Authorize(public: true)]` and are
  excluded from capabilities. `CqrsPass` rejects bare/mixed forms and maps public actions
  to the exact private Platform voter. There is no authorization YAML, resource
  partition, Application policy service or policy locator.
- One cohesive private lazy native voter per module receives a credential-free
  `AuthorizationToken`. The exact compiler-owned public voter has no dependencies or SQL,
  recognizes only inventoried public messages and is untagged when unused. The exact
  private `app.authorization.voter` iterator feeds an isolated native decision manager
  using `UnanimousStrategy(false)`. It does not replace firewall authorization or trace
  sensitive subjects. Public bus admission does not expose or bypass an HTTP route.
- Authorizing is generic and has no Authenticating/Task imports or account lookup.
  Its public APIs are `ChangeSubjectAssignments`, `ListSubjectAssignments`,
  `EvaluateSubjectEntitlements` and bounded role/capability use cases. There are no
  resource access/grant or initial-binding APIs. PHP and wire fields use `subjectId`.
- Assignment operations require an `assignments` operator or raw global
  `authorizing.manage`. Catalogue operations require a `catalogue` operator or raw
  global `authorizing.catalogue.manage`. Raw evaluation may allow orphan subjects;
  unknown permissions deny. Role assignments and direct grants are global and additive;
  there is no wildcard or cache.
- Runtime PostgreSQL role keys are immutable. Label/bundle updates require expected
  revision; retirement is irreversible. Active role definitions/memberships join every
  entitlement query. Retired assignments remain listable/removable but grant nothing.
  A role may explicitly combine any installed permissions across modules; there is no
  separate per-role permission maximum. Retain the total 4096 active membership-edge
  limit. Create-if-absent accepts only an exact active match.
- Setup defaults are global snapshots: `task_tracking.user` has the three Task
  permissions, `authorizing.administrator` has the two Authorizing permissions, and
  `application.administrator` has all five currently installed permissions. Future
  capabilities are not added automatically, including to `application.administrator`.
- The fresh Authorizing baseline has exactly the role, role-permission, role-assignment
  and permission-grant tables. Assignment natural keys are `(subject_id, role_key)` and
  `(subject_id, permission_key)`; there are no resource/scope columns, initial-binding
  table, `account_id` columns or assignment UUIDs. `Version20260915010000` owns this
  complete schema; `Version20260917010000` and `Version20260920020000` are deleted.
- Task's voter owns account checks and ownership. Account Create requires persisted self
  plus global create; operators may create unowned or for any persisted owner. Account
  Get/Complete require persisted actor, the corresponding global permission and exact
  ownership. Account List requires persisted self, global view and `--owner`/owner target
  equal to self. Missing, foreign and unowned Tasks deny accounts; `tasks` operators
  bypass these checks. Creation performs no automatic authorization grant.
- Task pages remain 1-100/default 50, owner-bound and keyset-based. Migration
  `Version20260920010000` adds `(owner_account_id, id)`. Expected business-read budgets
  are raw entitlement 1, Create 2, Get 3, List 3, Complete 4 and operator List 1.
- The all-permissions administrator is an explicit assignment snapshot, not a bypass:
  business voters still enforce actor kind, account existence, Task ownership and other
  contextual predicates. Authorization/account/Task reads remain snapshots during races.
- Validation/transaction/result/event ordering, caught-failure poisoning and actor/event
  frames remain. Completion locking and first-timestamp semantics remain unchanged.
  Four Task CLI adapters retain `tasks` scope; completion activity remains Task 11.

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
