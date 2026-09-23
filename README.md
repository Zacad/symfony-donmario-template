# DonMario Symfony application template

A Docker-first foundation for building applications with AI-assisted development.
Licensed under [MIT](LICENSE), copyright DonMario.

**Active work (2026-09-22): the [clean Authorizing/native-voter/Task-ownership model](docs/tasks/10-authorizing-rework.md)
is approved, implemented, verified and freshly reviewed; user acceptance is pending.** This is a
fresh-template-only correction: authorization data created by the
unaccepted intermediate reworks is not supported. The clean baseline rewrites
`Version20260915010000`, deletes `Version20260917010000` and
`Version20260920020000`, and owns exactly four global role/assignment tables. Task 11 has
not started; no commit/push is authorized. Earlier rework evidence remains historical
regression provenance, not verification of this clean model. Clean-model verification is
recorded in the active task; user acceptance remains pending.

**Latest accepted checkpoint (2026-09-16): Task 9.** [8b Authorizing model/management](docs/tasks/08-authorizing.md)
is **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED**. Following the full policy-only
enforcement design, the user said **“accept and proceed”**; main recorded 8b acceptance
and approval to implement [Task 9](docs/tasks/09-authorization-enforcement.md).
Task 9 is **IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-16**.
Including the `ActorKind` enum correction, setup, check (**1360 tests / 7959 assertions**),
E2E (**230 / 6306**) and fresh consumer (embedded **230 / 6310**) passed. Independent
implementation, correction and additional design reviews approved with no findings;
see [current handoff](docs/handoff.md#status-and-next-gate).
[Task 10 — TaskTracking use cases/CLI](docs/tasks/10-task-tracking.md) is **IMPLEMENTED,
VERIFIED, REVIEWED — SUPERSEDED BASELINE, NOT USER ACCEPTED**. Setup/check/E2E/consumer passed:
check **1467 / 8917**, E2E **252 / 7346**, consumer **252 / 7350**, all **53 E2E phases**.
Both fresh independent implementation reviewers approved with no findings.
Those Task 10 results verify the pre-rework implementation, not the approved correction.
8a was pushed as `367fdfe`; the user's 2026-09-16 “commit and push changes” request
authorizes this accepted 8b/9 delivery. Future commits/pushes need fresh authorization.
Earlier awaiting-8b-acceptance records are superseded; [8b evidence](docs/handoff.md#completed-8b-verification-and-review--2026-09-15) is retained.

## Quick start

Requirements: Linux x86_64, a **local Unix-socket Docker daemon**, Docker Compose
**2.24.4 or newer**, Git, and standard Linux shell utilities. Run as a non-root
user. PHP, Composer and Symfony CLI run in containers. Initial builds need network
access to Docker Hub, GitHub, Debian repositories and Composer repositories.

```sh
./bin/dev setup
```

Open **http://127.0.0.1:8080**. For a different port on first setup:

```sh
./bin/dev setup 8081
# Or let Docker allocate an unused port; setup prints the resulting address:
./bin/dev setup 0
```

Setup installs `composer.lock`, generates local credentials and JWT signing keys once, starts PostgreSQL,
validates/applies pending module and Platform Messaging migrations, and waits for the application. It can
be rerun without replacing credentials or resetting the database. A migration
failure returns nonzero before the success message. Symfony was originally scaffolded using Symfony CLI;
ordinary checkout setup uses that existing application.

## Development commands

| Command | Purpose |
| --- | --- |
| `./bin/dev setup [port]` | Build images, install dependencies, migrate and start |
| `./bin/dev up` | Start/recreate the app with current environment and wait for readiness |
| `./bin/dev down` | Stop services, retaining persistent volumes |
| `./bin/dev jwt-keys initialize\|validate\|rotate\|rotate-emergency\|retire` | Initialize/check keys or explicitly switch trust with key users stopped |
| `./bin/dev worker start\|stop\|status` | Manage the optional native async-event consumer |
| `./bin/dev console about` | Run Symfony console commands |
| `./bin/dev composer require package/name` | Manage dependencies inside the container |
| `./bin/dev composer exec -- php-cs-fixer fix --sequential` | Apply the Symfony coding standard |
| `./bin/dev check` | Validation/audit, lint, architecture, offline tests, PHPStan and style |
| `./bin/dev test` | Real HTTP and PostgreSQL E2E tests with failure/recovery phases |
| `./bin/dev verify-setup` | Verify first-use setup in a clean, disposable checkout |

`check` and `test` also work before local `setup`. Commands use explicit Compose
files, project identity and settings, regardless of the caller's directory.

The app uses a source bind mount and a separate named `var` volume. Symfony logs
go to stderr. To inspect running containers without displaying credentials:

```sh
# PROJECT is the PROJECT_ID value in var/docker/local.env (do not publish that file).
docker ps --filter label=com.docker.compose.project=PROJECT
docker logs CONTAINER
```

### Switch event delivery and run the worker

[Subtask 5b](docs/tasks/05b-native-event-bus.md) implements a thin EventBus with one
global transport switch. Implementation, container/PostgreSQL verification and
fresh independent reviews are complete, and the user accepted 5b on 2026-09-13; exact evidence
and resolved findings are in the task record.

Events default to **`sync://`**. To use PostgreSQL-backed asynchronous delivery,
after `./bin/dev setup` has installed dependencies and applied migrations:

```sh
export EVENT_TRANSPORT_DSN=doctrine://default
./bin/dev up             # Recreates the app when its environment changes
./bin/dev worker start
./bin/dev worker status
```

Export the setting in every shell used for these commands. The wrapper passes it
to Compose app/CLI, worker and runner environments; `check`/`test` select their own
isolated test modes. **Do not add it to `var/docker/local.env`**: that file has a
strict settings/credentials schema. Changing an export alone does not update an
already-running app. After source changes, reload the long-running worker with:

```sh
./bin/dev worker stop
./bin/dev worker start
```

After draining pending, in-flight and failed events, switch back with:

```sh
./bin/dev worker stop
export EVENT_TRANSPORT_DSN=sync://
./bin/dev up
```

Use the supported `./bin/dev worker` wrapper: **start refuses sync mode**. Raw
Symfony `messenger:consume` silently skips a synchronous receiver, so it is not a
mode-validation substitute. The optional worker profile is absent from ordinary
setup/up startup; `down` also removes its container while retaining database volumes.
The async worker's configured command is:

```sh
php bin/console messenger:consume events --time-limit=3600 --memory-limit=128M --limit=1000 --sleep=1 --no-interaction
```

Consumption is sequential with service resets between messages. The **3600-second,
128M and 1000-message limits are soft**, checked between messages; Compose restarts
the worker after exit. The **300-second lease has no keepalive** or hard per-handler
deadline. Module-owned idempotency must tolerate overlapping long-running redelivery
as well as crash/retry duplicates. The worker uses non-root application credentials,
no published ports, PCNTL signal handling and a 30-second stop grace period. Test
app, runner and worker use isolated credentials/resources and separate runtime caches.

The native `events` and `events_failed` queues share the retained
`public.platform_messaging_message` table and migration. Preflight found the old
queues empty. Old rows are neither converted nor deleted; new workers ignore old
opaque rows. A checkout with legacy rows must drain them using compatible old code
before upgrading. See [schema and compatibility](docs/architecture.md#schema-and-compatibility).

### Failed events

Ordinary failures get **three retries after the initial attempt**, delayed by
**1, 2 and 4 seconds**, without jitter. Symfony's recoverable/unrecoverable exception
classification remains native. Exhausted or unrecoverable messages stay in
`events_failed` until an operator retries or removes them:

```sh
./bin/dev console messenger:failed:show --transport=events_failed --max=50
./bin/dev console messenger:failed:show ID --transport=events_failed
./bin/dev console messenger:failed:retry ID --transport=events_failed --force
./bin/dev console messenger:failed:remove ID --transport=events_failed --force
```

These are native operator commands with redacted message displays. **Retry can run
handlers inline in the console process**; it has native retry/ACK semantics. Native
`HandledStamp` partial-success tracking is retained, and consumers still need
at-least-once idempotency. Diagnostics expose fixed metadata, not payloads or arbitrary
exception details. Queue/database access is trusted: stored events are sensitive,
and malformed-message failure storage may retain original wire data. Restrict access
to that storage and its backups; output redaction does not sanitize stored payloads.

## Web authentication

[Subtask 6](docs/tasks/06-web-authentication.md) includes the user's approved
2026-09-13 correction: registration owns password-policy validation and hashing.
Subtask 6, including the correction, is **VERIFIED, REVIEWED and USER ACCEPTED on
2026-09-13.** Post-correction setup, check, HTTP/PostgreSQL tests and fresh-consumer
verification passed. Check: **610 tests / 3942 assertions**; E2E: **137 tests / 1991
assertions**; consumer embedded E2E: **137 tests / 1990 assertions**. The fresh
independent correction reviewer approved with no concrete findings.
Subtask 7 has approved implementation, including the `/api/me` QueryBus correction;
it is verified, independently reviewed and user accepted on 2026-09-14. Subtask 6's
results above are its accepted historical checkpoint.

Provision an account after setup, then open `/login`:

```sh
./bin/dev console app:account:provision person@example.com
```

The terminal prompts hide both password and confirmation and fail if hidden input
is unavailable; there is no visible fallback. Automation can pipe private input to
`./bin/dev console app:account:provision person@example.com --password-stdin --no-interaction`.
Stdin is bounded and removes only one optional terminal LF/CRLF. Keep passwords out
of command arguments, environment variables, URL queries and logs.
The CLI handles secure input, confirmation, transport framing and fixed errors;
it dispatches raw email/password without business validation or a hasher.

- Email is ASCII, trimmed of outer ASCII whitespace, lowercased and at most 254 bytes.
  Passwords require at least 15 Unicode characters and at most 4096 bytes of valid
  UTF-8; spaces are preserved, NUL and line breaks rejected.
- Native Symfony form login uses CSRF and throttling; `/account` requires full
  authentication. Native logout is **POST `/logout` with CSRF**. Invalid login CSRF
  preserves an existing authenticated session; a late password-migration failure
  explicitly clears authentication. Successful login rotates the session.
- Provisioning and hash upgrades write through synchronous command-bus commands.
  `RegisterAccountCommand(email, password)` carries plaintext in memory; its handler
  normalizes email, validates `PasswordPolicy`, hashes through the Domain
  `PasswordHasher` port and adds the Account. `SymfonyPasswordHasher` delegates only
  to the native hasher. Registration validation, hashing and persistence run **inside
  the existing command transaction**. Native Symfony computes password upgrades
  before dispatching the separate hash-only `UpgradePasswordHashCommand`; its
  transaction uses compare-and-swap (CAS) to prevent stale overwrites.
  Native login/session refresh reads module-local Domain credentials through the
  provider as an explicit exception; there is no `LoginCommand`. Domain has no
  Symfony Security dependency. Sessions store only a password-hash fingerprint,
  never the reusable hash or plaintext password.
- Plaintext is not persisted, queued or logged, but the public readonly registration
  command, Messenger envelope and validation-exception objects can retain it in
  memory. Unsetting a CLI local does not guarantee erasure. Do not dump these objects;
  there is no public credential query, result or event.
- Browser-session cookies are `dm_<PROJECT_ID>_<env>`, host-only, path `/`, HttpOnly,
  SameSite=Lax and Secure=auto. Files live in `var/sessions/<env>` outside cache.
  Native session GC is cleanup, **not a hard idle or absolute TTL**.
- Dedicated native limiter storage/locks live in `var/security/<env>`, with stable
  secret-based keys: **5 failures/minute per normalized identifier/IP** and
  **25/IP/5 minutes**. This single-host baseline survives cache rebuilds; native
  filesystem I/O is not guaranteed fail-closed and concurrent attempts can race.
  Lock files accumulate; do not unlink them while authentication processes are active.
- Caddy limits authentication POST bodies to **16 KiB** (413 when oversized),
  requires Content-Length framing (411 otherwise), and rejects external
  `/index.php` front-controller aliases with 404.

The web firewall excludes `/api`. See [the architecture](docs/architecture.md#web-authentication-subtask-6)
for the exact provider/principal exceptions and security/performance limits.

## JWT API authentication

[Subtask 7](docs/tasks/07-jwt-authentication.md) is **IMPLEMENTED, VERIFIED, REVIEWED
and USER ACCEPTED on 2026-09-14**, following the user's “i accept, proceed”
implementation approval, including the identity-query correction. Final setup,
full checks, HTTP/PostgreSQL E2E and fresh-consumer verification passed. Both fresh
independent reviewers approved after inspecting code/evidence; verification and
reviews completed on 2026-09-13. Latest accepted is **Task 9 policy-only enforcement (2026-09-16)**.
LexikJWTAuthenticationBundle **3.2.0**, Lcobucci JWT **5.6.0** and API Platform Symfony
**4.3.19** are installed. Task 9 policy enforcement is verified/reviewed and user
accepted; business API adapters retain their later approval gates.

Provision an account using the hidden-input command above. A same-origin client sends
these contracts over HTTPS outside loopback development:

| Route | Request and response |
| --- | --- |
| POST `/api/login` | `Content-Type: application/json`, framed body containing exactly string `email` and `password` fields. Success: `{"access_token":"<JWT>","token_type":"Bearer","expires_in":900}`. |
| GET `/api/me` | `Authorization: Bearer <JWT>`. Success: `{"id":"<account UUID>","email":"<current canonical email>"}`. |
| GET `/api/docs.json` | Public JSON OpenAPI contract for login and identity; this exact documentation path has `security: false`. |

The documentation route remains public even when an invalid bearer header is sent.

The angle-bracket values describe response fields, not credentials to paste into a
shell. Read credentials through private client input, send them only in the JSON body,
and keep the returned token in client memory. Never put passwords/tokens in argv,
environment variables, shell history, URLs or logs. There is no browser application
shipped here: the SPA contract is same-origin, memory-only storage; reload or expiry
requires login again. No persistent browser token storage or cross-origin setup is supplied.

- Native Symfony `json_login` verifies credentials and completes password migration
  before a fully authenticated thin controller asks Lexik to issue a token. There
  is no early success handler, manual password authenticator or `LoginCommand`.
- Login and bearer firewalls are stateless. API requests neither create/invalidate
  web sessions nor accept web cookies as authentication. Tokens are accepted only
  from the Authorization Bearer header: at most **8 KiB of token plus the 7-byte
  `Bearer ` prefix**, bounded before parsing. Cookie, query and body token extraction
  are disabled.
- Login bodies are limited to **16 KiB**, with Content-Length required by Caddy.
  Strict JSON shape/type validation and email normalization run before native login;
  web/API attempts share the existing limiter and its documented single-host limits.
  Errors are fixed and responses use no-store. Invalid input is 400, missing framing
  411, oversized body 413, wrong content type 415, throttling 429, failed authentication
  401 and operational authentication failure 503. Login supports POST only (405 otherwise).
- Tokens use **RS256 / RSA 3072**, **900-second lifetime**, **zero clock skew** and
  `typ: JWT`. The only payload claims are `sub`, `iss`, `aud`, `iat`, `nbf`, `exp`:
  UUID subject, project/environment-specific issuer/audience, `nbf = iat` and exactly
  `exp = iat + 900`. Signature and normalized claims are checked before account lookup.
  Tokens contain no email, password-derived values or business permissions. Payloads
  are readable, not encrypted.
- Every valid bearer request performs a live indexed UUID account lookup. `/api/me`
  then uses `QueryBus` → `Application/GetAccountIdentity/GetAccountIdentityQuery` →
  handler → Domain `findIdentityById` → `GetAccountIdentityResult(id, email)` → UI
  resource. This deliberate **second indexed read** returns current identity without
  publishing credentials. Deleted accounts are denied (401); deletion between the
  authentication read and identity query returns 404.
- There is no refresh token, disabled-account state or per-token revocation store.
  Password changes/rehashes and web logout do **not** revoke existing JWTs. Client
  logout discards the token; a copied token remains replayable until expiry or removal
  of its signing key from verification trust. Memory-only storage does not prevent active XSS.

### JWT key operations

Compose's dedicated **`jwt_keys`** volume (`PROJECT_ID_jwt_keys`) holds local,
unencrypted PKCS8 signing PEM and matching public PEM, with **0700 directories /
0600 files**, owned by the configured application UID. The app/console mount is
read-only; the test runner mounts only its isolated test keys read-only. Workers
have no key mount. No key is generated during image builds, cache compilation,
ordinary requests or worker startup. The short-lived, network-disabled key helper
is the writer.

`./bin/dev setup` initializes once and validates/retains keys on reruns;
`./bin/dev up` validates them before startup. `./bin/dev jwt-keys initialize` and
`./bin/dev jwt-keys validate` expose those operations after settings/images exist.
Independent **`var/docker/jwt-initialized`** metadata matches the volume's `.identity`
and detects loss/replacement of initialized keys. Back up both with local settings
and valuable data; clean consumer exports exclude them and generate independent keys.

The helper writes a complete generation, atomically switches the `current` symlink,
then validates the published state. Published `active`, `previous` and `verification.json` paths follow
that generation. Lexik's native key-loader service receives its additional-public-key
array lazily from `/app/var/jwt/verification.json`; rotation does not rewrite config
or require build-time keys. Issuer is `urn:donmario:<APP_INSTANCE_ID>:<env>` and audience
adds `:api`; Compose derives APP_INSTANCE_ID from PROJECT_ID. Keep project identity,
APP_SECRET and keys consistent; development, test and consumers have separate namespaces.

For planned rotation, record when old-key issuance stopped and your retirement deadline:

```sh
./bin/dev down
./bin/dev jwt-keys rotate
./bin/dev up
# Before the recorded deadline, stop and remove previous-key trust:
./bin/dev down
./bin/dev jwt-keys retire
./bin/dev up
```

All key-using app/console containers must be stopped for `rotate`, `rotate-emergency`
and `retire`; the wrapper refuses these operations while any running container mounts the
volume. Restart an optional async worker separately using the event commands above.
Planned rotation retains **one old public key only**, deletes obsolete signing
generations, and refuses a second overlap rotation until retirement. **The operator
must retire old trust within 900 seconds of stopping old-key issuance**; downtime
counts. There is no automatic retirement timer. Earlier retirement invalidates any
remaining old tokens.

For compromise response, use `./bin/dev down`, then
`./bin/dev jwt-keys rotate-emergency`, then `./bin/dev up`. This publishes a fresh
pair with no old-key trust; old tokens are rejected after restart. Do not restart
after a failed switch until retained key/metadata state has been validated or restored.

## Authorizing operator management

For a plain-language explanation of actors, handler declarations, middleware, voters,
permissions, roles, persistence, and extension steps, read the
[authorization engineer guide](docs/authorization.md).

Authorizing provides generic opaque-subject global assignment/evaluation and persisted
runtime roles. There are no resource-access/grant or initial-binding APIs and no HTTP/API
management routes. Trusted console adapters use `assignments` authority for assignment/
evaluation and `catalogue` authority for runtime-role changes. Account actors may instead
hold raw global `authorizing.manage` or `authorizing.catalogue.manage`.

### Capabilities, runtime roles and setup defaults

Each command/query handler declares exactly one `#[Authorize]`. Restricted declarations
name a concrete final same-module voter. Permission-bearing declarations use the module's
string-backed `*Permission` or `*PermissionEnum` enum and stable label; contextual declarations omit the
permission. Actor-unrestricted actions instead declare only `#[Authorize(public: true)]`.
`CqrsPass` aggregates global installed capabilities, validates the mutually exclusive
forms and routes public actions through the exact Platform public-access voter. Public
actions do not enter the capability catalogue. There is no editable authorization YAML
or per-message resource partition.

Role definitions and assignments are runtime PostgreSQL data created by the rewritten
fresh baseline `Version20260915010000`. Setup safely seeds these exact active defaults on
every run:

| Role | Explicit global permission snapshot |
| --- | --- |
| `task_tracking.user` | `task_tracking.task.create`, `task_tracking.task.view`, `task_tracking.task.complete` |
| `authorizing.administrator` | `authorizing.manage`, `authorizing.catalogue.manage` |
| `application.administrator` | All five currently installed permissions |

These are snapshots. Future capabilities are never added automatically, including to
`application.administrator`. Assigning that role is not a business-context bypass:
module voters still enforce actor kind, account existence, Task ownership and any other
contextual predicates.

The baseline owns exactly `authorizing_role`, `authorizing_role_permission`,
`authorizing_role_assignment` and `authorizing_permission_grant`. Role assignments use
`(subject_id, role_key)` and direct grants use `(subject_id, permission_key)` as natural
primary keys. There are no resource assignment/grant or initial-binding tables,
scope/resource columns, physical `account_id` columns or synthetic assignment IDs.
`Version20260917010000` and `Version20260920020000` are absent. This template does not
upgrade databases created by the unaccepted intermediate authorization designs; recreate
such a database rather than attempting to preserve or translate those rows.

To add a capability, add a reviewed enum case and handler `#[Authorize]` declaration in
the owning module; compilation rejects foreign enums, inconsistent labels and missing or
wrong voter mappings. Roles may explicitly combine installed permissions from any module.
To define or revise one:

```sh
./bin/dev console app:authorization:role:define task_tracking.reviewer 'Task reviewer' task_tracking.task.view --if-absent
# Update an existing active role using the revision returned by the prior result:
./bin/dev console app:authorization:role:define task_tracking.reviewer 'Task read reviewer' task_tracking.task.view --expected-revision=1
```

Role keys are immutable. Label/bundle updates require the exact current revision;
retirement through `RetireRoleCommand` is irreversible. There is no separate per-role
permission maximum; the total remains bounded to 4096 active role-permission edges.
Role/capability Application APIs include `DefineRoleCommand`, `GetRoleQuery`,
`ListRolesQuery`, `RetireRoleCommand` and `ListAuthorizationCapabilitiesQuery`.

Effective permissions are the additive union of active roles and direct grants. All
entitlement queries join active role definitions and memberships; retired assignments
remain listable/removable but grant nothing. Removing the last source denies subsequent
checks after commit, while already-authorized work may finish. There is no wildcard,
hierarchy, cache, or JWT/session permission authority.

### Subject assignments and checks

Replace `<SUBJECT_UUID>` with an application UUID. Authorizing does not check whether a
subject represents an account, so authorized callers may manage and evaluate orphan
subjects. New assignments are global.

```sh
./bin/dev console app:authorization:role:assign '<SUBJECT_UUID>' task_tracking.user
./bin/dev console app:authorization:role:remove '<SUBJECT_UUID>' task_tracking.user
./bin/dev console app:authorization:permission:grant '<SUBJECT_UUID>' task_tracking.task.view
./bin/dev console app:authorization:permission:revoke '<SUBJECT_UUID>' task_tracking.task.view
./bin/dev console app:authorization:check '<SUBJECT_UUID>' task_tracking.task.view
./bin/dev console app:authorization:assignments '<SUBJECT_UUID>' --limit=50
# Continue using the previous response's non-null next value:
./bin/dev console app:authorization:assignments '<SUBJECT_UUID>' --limit=50 --after='<NEXT_CURSOR>'
```

Changes return JSON `requested`, `added`, `removed`, `unchanged` counts based on rows
actually changed, so repeated additions/removals are idempotent. Checks return
`{"allowed":true}` or `{"allowed":false}`. **Exit 0 includes a deny decision**; inspect
the JSON boolean. Invalid input exits **2** with `Invalid authorization input.`;
operational failure exits **1** with `Authorization operation failed.`. Native command
syntax errors retain Symfony Console diagnostics.

Listing returns `{"assignments":[...],"next":null}` or an opaque `next` string. Each row
contains only `kind` and `key`. Pages are **1–100 items, default 50**, ordered by role
assignments then direct permission grants and by key within each kind. There are no totals,
offsets or cross-page snapshot guarantees; concurrent changes can affect later pages.
`--after` is at most **512 characters**, strictly decoded and bound to the subject plus its
`kind`/`key` continuation reference. It is pagination data, not authority. Removal remains
valid for orphan subjects and retired or no-longer-installed keys.

### Batch JSON stdin

The remaining two commands accept a top-level JSON array on stdin: **1–100 objects,
at most 64 KiB, JSON depth 16**, with exact field names. Replace UUID placeholders
inside these examples before running them. Assignment changes always contain exactly
`operation`, `kind` and `key`.

```sh
./bin/dev console app:authorization:change-batch '<SUBJECT_UUID>' <<'JSON'
[
  {"operation":"add","kind":"role","key":"task_tracking.user"},
  {"operation":"remove","kind":"permission","key":"retired.permission"}
]
JSON

./bin/dev console app:authorization:check-batch <<'JSON'
[
  {"subjectId":"<SUBJECT_UUID>","permission":"task_tracking.task.create"},
  {"subjectId":"<SUBJECT_UUID>","permission":"task_tracking.task.view"}
]
JSON
```

`change-batch` applies one subject's entire batch atomically. `operation` is `add` or
`remove`; `kind` is `role` or `permission`. Duplicate/conflicting natural keys fail
the batch. `check-batch` may span subjects and returns `{"decisions":[{"allowed":true},...]}`
in input order. Both use the same exit/error contracts as single-item commands.

Application callers use `ChangeSubjectAssignmentsCommand`,
`EvaluateSubjectEntitlementsQuery` and `ListSubjectAssignmentsQuery` through the buses.
Authorizing never calls Authenticating or reads Task state. Generic resource and
initial-binding APIs and schema do not exist. Mutations retain the subject-scoped advisory
lock, grouped bounded DML and root transaction ownership; handlers/repositories do not
flush, commit or retry.

Raw evaluation uses one business SQL read and assignment listing one bounded query.
No authorization cache is introduced. Clean-model verification and fresh review are still
pending. See the [active status](docs/handoff.md#status-and-next-gate)
and [architecture](docs/architecture.md#authorization-and-runtime-roles-current-rework).

## Runtime and local configuration

- Symfony **8.1**, PHP **8.5**, FrankenPHP classic mode, Twig/AssetMapper and PostgreSQL **18**.
- Symfony components and tools are locked in `composer.lock`; base images and the
  Dockerfile frontend are digest-pinned. Symfony CLI's versioned archive is
  SHA-256-verified before extraction.
- The development HTTP port binds to `127.0.0.1`. PostgreSQL has no host port.
- PostgreSQL initialization creates a nonsuperuser `app` role and an owned database.
  The initialization hook is packaged in the database image, independently of
  host checkout permissions. PostgreSQL 18 data lives under a volume mounted at
  `/var/lib/postgresql`.
- `/health/live` checks application liveness. `/health/ready` executes a small
  DBAL probe and returns only `OK` or `Unavailable`; `/` and liveness do not need
  a working database.
- The readiness connection has a two-second PDO connection timeout and a
  one-second PostgreSQL statement timeout. It closes after each probe. This is
  separate from the default connection used by future application persistence.
  Application DNS retries are bounded too, since a stopped Compose service
  disappears from Docker DNS and PDO's connection limit does not cover resolution.
- Symfony Cache is available with its standard filesystem defaults. The
  separately designed cache subtask adds application examples and a tested Valkey option.

Generated settings are stored in **`var/docker/local.env`** with owner-only
permissions. That file is excluded from Git and Docker build contexts. Compose
passes only application credentials to PHP; the separate PostgreSQL bootstrap
password stays with the database. The application's `/app/var` volume masks the
host settings directory from ordinary web and console containers. Trusted
bootstrap helpers intentionally have access to it.

To change an existing port, edit only `APP_PORT` in that file, then run `setup`.
Keep `PROJECT_ID`, database names and credentials consistent with existing data.
Back up local settings alongside valuable development data. Clean exports used
for new applications must exclude local settings and runtime data.

### Recovery

- **Missing/incomplete settings with existing volumes:** restore the original
  `var/docker/local.env`. Setup refuses to guess replacement database passwords.
  Moving a checkout while also losing its settings requires manual recovery of
  the original project identity and credentials.
- **Interrupted first database initialization:** PostgreSQL only executes init
  hooks on empty data directories. Inspect the database logs. Recover the role
  and database using the original administrator credentials, or deliberately
  recreate only this checkout's disposable data volume if it contains nothing
  valuable. Setup never performs that deletion automatically.
- **Stale setup lock:** after confirming no setup/Composer process is active,
  remove the empty `var/docker/setup.lock` directory and rerun.
- **Missing/corrupt JWT keys with initialization evidence:** stop key users and
  preserve the volume and `var/docker/jwt-initialized`. Restore the matching original
  key volume and metadata from backup, then run `./bin/dev jwt-keys validate` before
  `./bin/dev up`. Setup refuses silent regeneration. Do not delete the marker to
  disguise key loss.
- **Interrupted first JWT initialization:** preserve the partial volume and metadata
  first. If a valid published generation and volume `.identity` exist but the external
  marker was not written, `./bin/dev setup` validates the retained keys and completes
  the marker. If publication or `.identity` is incomplete, setup refuses and preserves
  the state: restore a matching complete backup. Only for a confirmed disposable,
  never-completed first initialization with no valuable signing keys may an operator
  explicitly remove this checkout's `PROJECT_ID_jwt_keys` volume and any partial
  `var/docker/jwt-initialized` or `var/docker/jwt-initialized.pending-*` files, keeping local settings and database
  intact, then rerun setup. Stop all key users/helpers first; setup never resets this
  state automatically. After an interrupted rotation with valid retained state,
  setup can validate the selected generation and finish pruning obsolete generations;
  check which key is selected and honor the original retirement deadline.
- **Changed host UID/GID:** stop this checkout, update `LOCAL_UID`/`LOCAL_GID` to
  your current IDs, and recreate only its disposable `PROJECT_runtime` cache/log
  volume before `setup`. Repair ownership of your source/vendor files using your
  normal host administration process. Independently repair the retained `jwt_keys`
  volume's directory/file ownership and local JWT metadata to the new application
  UID/GID, preserving 0700 directories, 0600 files and generation symlinks; validate
  after rebuilding with setup. Do not delete valuable signing keys as cache recovery.
  PostgreSQL retains its own UID and data volume.
- **Readiness failure:** inspect application/database container logs. The HTTP
  probe deliberately omits connection details. Dependency audits failing due to
  network errors are failed checks, not a clean audit result.

Shared `:z` labels apply only to this checkout's bind mounts. The workflow is
verified on an SELinux-enforcing host; actual container SELinux confinement
depends on Docker daemon configuration. This is a development/test runtime.

## Modules and persistence

`TaskTracking` contains a Task with UUID, title, nullable immutable owner account UUID
and nullable completion timestamp, its repository port/adapter and module migrations
for `public.task_tracking_task`. UUIDs are generated by Symfony UID; PostgreSQL uses
its native `uuid` type without an extension. Create/Get/List/Complete use synchronous
Messenger command/query buses; CLI exposes all four and dev/test HTTP exposes Create/Get.

Repository contracts belong to the module's Domain:

```text
TaskTracking/Domain/Task.php
TaskTracking/Domain/TaskRepository.php
TaskTracking/Infrastructure/Persistence/DoctrineTaskRepository.php
```

`TaskRepository` exposes `add`, `find`, locking `findForCompletion` and bounded `findPage`
operations.
`DoctrineTaskRepository` implements it by composing the default EntityManager.
The module's service configuration explicitly binds the interface to its adapter;
application handlers inject the Domain interface. Repositories are module-internal
ports; other modules use the public command/query contracts.

Commands, queries, handlers and any result DTOs are grouped by use case:

```text
TaskTracking/Application/CreateTask/CreateTaskCommand.php
TaskTracking/Application/CreateTask/CreateTaskHandler.php
TaskTracking/Application/CreateTask/TaskCreatedEvent.php
TaskTracking/Application/GetTask/GetTaskQuery.php
TaskTracking/Application/GetTask/GetTaskHandler.php
TaskTracking/Application/GetTask/GetTaskResult.php
TaskTracking/Application/ListTasks/ListTasksQuery.php
TaskTracking/Application/ListTasks/ListTasksHandler.php
TaskTracking/Application/ListTasks/ListTasksResult.php
TaskTracking/Application/ListTasks/TaskListItemResult.php
TaskTracking/Application/CompleteTask/CompleteTaskCommand.php
TaskTracking/Application/CompleteTask/CompleteTaskHandler.php
TaskTracking/Application/CompleteTask/CompleteTaskResult.php
```

Subtask 3b introduced Create/Get on the boundary rules established in 3a; Task 10
expands the model and adds List/Complete. The exact
`Application/<UseCase>/<Name>{Command,Query,Result,Input,Event}` types form the public data API;
neighboring handlers/helpers remain private module implementation. Domain is
independent of Application DTOs and public events. Subtask 4 adds internal
`Domain/Event/*Event` facts explicitly translated by Application to public events;
public event payloads cannot carry command/query/result/input DTOs or module internals. There is
no current `Contract` directory. See [architecture](docs/architecture.md) for exact
data restrictions and [Subtask 5b](docs/tasks/05b-native-event-bus.md) for the active
native-event design and verification status.

`add()` schedules persistence; flushing and committing belong to the application
transaction boundary. The entity uses Doctrine mapping attributes but never names
an infrastructure repository through `repositoryClass`. Domain/Application source
cannot depend on runtime Doctrine/PDO APIs or outward module layers. Compiled DI
accepts an infrastructure adapter through a declared, same-module Domain interface
and rejects direct concrete injection, including through named aliases.

### Task 10 — TaskTracking use cases and CLI

The Task use cases are included in the approved
[authorization correction](docs/tasks/10-authorizing-rework.md). The clean Authorizing
model is implemented, verified and freshly reviewed; user acceptance is pending. The
original [Task 10 record](docs/tasks/10-task-tracking.md) and later intermediate runs are
retained as historical regression evidence only.

All four CLI commands explicitly run in trusted operator `tasks` scope. Shell access
is their authority; `--owner` selects a business target, not an actor.
Replace the quoted UUID/cursor placeholders with actual values:

```sh
./bin/dev console app:task:create 'Unowned task'
./bin/dev console app:task:create 'Owned task' --owner='<ACCOUNT_UUID>'
# Create prints only the new UUID; use it below:
./bin/dev console app:task:show '<TASK_UUID>'
./bin/dev console app:task:list --limit=50
./bin/dev console app:task:list --owner='<ACCOUNT_UUID>' --limit=50
# Continue with the same owner target and the preceding non-null next value:
./bin/dev console app:task:list --owner='<ACCOUNT_UUID>' --limit=50 --after='<NEXT_CURSOR>'
./bin/dev console app:task:complete '<TASK_UUID>'
./bin/dev console app:task:show '<TASK_UUID>'
```

Show returns `id`, `title`, `ownerAccountId` and `completedAt`. List returns
`{"tasks":[...],"next":null}` or an opaque `next` string, with the same four fields
on each task. Completion returns `id`, `changed` and `completedAt`; repeat completion
succeeds with `changed: false` and preserves the first timestamp. Timestamps are UTC
whole seconds, formatted `YYYY-MM-DDTHH:MM:SSZ`. Null owner means unowned; null
completion means open. Migration `Version20260916010000` adds nullable columns and
retains legacy tasks as unowned/open. Migration `Version20260920010000` adds the
`(owner_account_id, id)` index used by owner-filtered keyset pages.

#### Ownership, permission and integration contracts

- `CreateTaskCommand(title, ownerAccountId = null)` permits account actors only with
  global `task_tracking.task.create`, self ownership and a persisted account. A `tasks`
  operator may omit the owner or select an already-persisted account. Creation publishes
  the existing UUID-only `TaskCreatedEvent` but performs no authorization assignment or
  grant.
- Ownership is immutable and is the Task voter's business context. Account Get and
  Complete require a persisted actor, the corresponding global permission and exact
  ownership. Missing, foreign and unowned Tasks deny accounts. Account List requires a
  persisted self target, global view permission and owner equality. A `tasks` operator
  bypasses account permission/ownership checks and may list all tasks or filter by owner.
- Global role/direct grants supply only coarse permission. Even
  `application.administrator` is an explicit five-permission snapshot and does not bypass
  ownership or other contextual voter predicates.
- Infrastructure brackets event delivery with a frame, including native synchronous
  redispatch, and unwinds it on success/failure. It keeps the pinned actor alive across
  synchronous delivery; the authorization token exposes no caller fact or caller-derived
  authority. Sync retains the actor and producer transaction; ordinary worker delivery
  starts anonymous and listener commands retain independent transaction/reset boundaries.
  There is no queued publisher identity or inherited producer authority.
- Cross-module work uses public bus data only: TaskTracking reads its tasks, Authorizing
  evaluates global entitlements, and Authenticating checks accounts. There is no
  cross-module SQL, join, FK or ORM association. Account existence is a snapshot;
  deleted accounts can leave orphan owner UUIDs. Same-transaction
  permission checks do not serialize against revocation; already-authorized work can
  finish. Completion locks protect task state, not permission revocation.

#### Listing and completion bounds

`ListTasksQuery(ownerAccountId = null, limit = 50, after = null)` admits an account only
for its own non-null owner target after account existence and global view checks.
Operators may target any owner; omitting `--owner` lists all tasks. Targeted listing
returns tasks owned by that UUID and includes open and completed tasks.

Pages are **1–100, default 50**, in ascending UUID keyset order. Canonical cursors are
at most **512 characters** and bound to the owner target (including operator-wide null);
they are pagination data, not authority. Admission is recalculated on every page. The
owner/index query fetches one lookahead row. There are no refill loops, totals, offsets
or cross-page snapshots; concurrent permissions, account or Task changes can affect a
later page.

Expected business-read budgets excluding authentication are raw entitlement **1**,
account Create **2**, Get **3**, List **3**, Complete **4**, and operator List **1**.
Owner listing uses `task_tracking_task_owner_id_idx`; query/row bounds do not guarantee
latency or a universal PostgreSQL plan. The redesigned budgets and N=100 owner-index
plans pass actual PostgreSQL verification.

Completion of persisted tasks takes a pessimistic owning-row lock within the command
transaction and samples time after acquisition. Pending insertions remain in the unit
of work and need no persisted-row lock. For an already-managed Task, matching
locked/original state preserves local changes; changed
database state refreshes a clean object, but conflicts with local dirty state fail
rather than overwrite it. Scheduled deletions or missing rows with managed state also
conflict. Only the root bus flushes/commits; no automatic retry is supplied.
UTC whole-second normalization matches
Doctrine persistence precision. See [architecture](docs/architecture.md#tasktracking-use-cases-and-cli-task-10)
for exact locking and integration boundaries.

#### CLI errors and HTTP demonstration

| Outcome | Exit / output |
| --- | --- |
| Success, including repeated completion or an empty list | **0**; UUID or JSON as above |
| Invalid `--owner`, or invalid list/complete input | **2**; `Invalid task input.` |
| Create/show message validation | **2**; native `field: message` violation lines |
| Missing task on show/complete | **1**; `Task not found.` |
| Operational failure, completion conflict, or well-formed but nonexistent owner on create | **1**; `Task operation failed.` |

Native command-line syntax errors retain Symfony Console diagnostics. A nonexistent
selected owner fails creation with no committed Task; it is not exit 2.
Omitting `--owner` is valid unowned creation.

For HTTP, provision an account, sign in at `/login`, and assign its UUID
`task_tracking.user` or grant `task_tracking.task.create` globally. The title-only
adapter derives the owner from native identity; the Task voter independently enforces
self ownership. Reading the created task also requires global view permission and exact
ownership.
From the same-origin browser console after login:

```js
const created = await fetch('/_demo/tasks', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({title: 'Created over HTTP'})
});
const task = await created.json();
await fetch(`/_demo/tasks/${task.id}`).then(response => response.json());
```

These **dev/test-only** HTTP demonstration routes use native web-session identity;
an API bearer token supplies no identity here. For valid requests, anonymous denial
is **401**, authenticated denial **403**, both with fixed `{"error":"Access denied."}`.
Create requires global `task_tracking.task.create` and self ownership; Get requires
global `task_tracking.task.view` plus exact ownership. Missing, foreign and unowned
Tasks deny account requests with the same fixed 403. Authorized POST returns 201 with
the UUID and Location; authorized GET returns `id`, `title`, `ownerAccountId`,
`completedAt` JSON. A deletion after admission may still produce 404. Payload-shape errors are
400, bodies above 4 KiB are 413, non-JSON POSTs are 415 and message validation is 422.
Responses use no-store; unexpected failures expose only `{"error":"operation_failed"}`
with HTTP 500. List/Complete have no HTTP adapter in this task; Twig, business API and
completion activity retain their later approval gates.

Application/UI adapters inject `App\Platform\Messaging\CommandBus` or `QueryBus`.
The outer command validates input, starts the default Doctrine transaction, evaluates
native admission, runs its handler, validates returned DTO data, then flushes and commits
before returning. Nested commands share that unit;
a nested failure prevents the outer commit even if caught. Independent operations
reset ORM/context state. Queries never automatically flush, and cannot dispatch
commands. Repositories continue to schedule persistence without flushing.

Module-owned `Resources/config/validation.yaml` files must be explicitly listed in
`framework.validation.mapping.paths`. Handlers use `#[AsMessageHandler(bus: ...)]`
with one exact DTO argument and a public-data return type, beside their message.
Compilation rejects missing/duplicate/wrong-bus handlers and invalid middleware
wiring. Each command/query handler also declares exactly one `#[Authorize]` as below.
Raw Messenger services are internal infrastructure, not module-facing APIs.
The standard framework messages visible in `debug:messenger` are rejected by the
application message policy; command/query helpers dispatch only their inventoried DTOs.

### Native command/query authorization

Every command/query handler declares exactly one `#[Authorize]`. These are the only valid
forms:

```php
#[Authorize(public: true)]
#[Authorize(voter: TaskTrackingVoter::class)]
#[Authorize(voter: TaskTrackingVoter::class, permission: TaskPermission::View, label: 'View tasks')]
```

The public form is actor-unrestricted at the command/query bus boundary. It does not
create an HTTP route or bypass firewall, CSRF, request limits, validation, transactions
or result validation. Public actions cannot be revoked through assignments and do not
enter the capability catalogue. The other forms name a concrete final same-module voter;
permission enums are module-owned and string-backed. Bare declarations and combinations
of `public: true` with voter, permission or label metadata fail compilation. `CqrsPass`
validates declarations, stable labels, global capabilities and exact voter routing, then
injects the installed capability descriptors into `AuthorizationCatalogService`.

Each module owns one cohesive private lazy voter for restricted actions. Public metadata
is compiled to the exact private lazy Platform `PublicAccessVoter`; handlers cannot name,
inject or call it. It recognizes only compiler-inventoried public messages and grants only
the internal token, without SQL. Its decision-manager tag is removed when no public action
exists. Authorization middleware calls a dedicated private native `AccessDecisionManager`
containing only the exact `app.authorization.voter` iterator and
`UnanimousStrategy(false)`. This does not replace Symfony's firewall/global manager. The
explicit `AuthorizationToken` has no credentials or principal payload; it carries
immutable Actor and support-read provenance.
Invocation/transaction ownership and event frames remain infrastructure context. The
business manager and voters remain untraced so
sensitive command subjects are not retained by native authorization tracing.

Native fully authenticated HTTP supplies the account actor UUID. Exact trusted adapters
establish `OperatorExecution` scopes: provisioning uses `accounts`, assignment commands
use `assignments`, runtime-role commands use `catalogue`, and Task CLI commands use
`tasks`. Only the native account provider uses account-bound authentication scope for
hash upgrades. CLI/worker execution alone grants nothing; actor authority cannot change
during bus execution.

The Authorizing voter admits assignment management through `assignments` or raw global
`authorizing.manage`, and catalogue management through `catalogue` or raw global
`authorizing.catalogue.manage`. Raw entitlement evaluation is support-read-only or
`assignments`. Authenticating's account-existence route is support-read-only. Task's
voter owns the account checks and entitlement composition needed by Task workflows.

Voters may use QueryBus and owning Domain read ports/state under the exact source/DI
rules; they cannot inject handlers, raw buses, ORM/SQL or outward adapters. These
guardrails are not an arbitrary PHP/SQL sandbox. Input validation precedes admission;
command admission runs inside the owned transaction. Caught nested failures invalidate
the root. Queries gain no automatic transaction, and live reads do not serialize
revocation. Expected budgets excluding authentication are one raw entitlement read,
two for Task Create, three for Get/List, four for Complete and one for operator List;
the clean baseline must reverify the existing N=100 PostgreSQL coverage.

### Application DTO collections (8a: user accepted 2026-09-15)

Commands, queries, results and nested `*Input` data support homogeneous nonnullable
native arrays declared with constructor `@param list<T>`. Input lives at the same exact use-case
depth, is excluded from services, cannot be dispatched and cannot be a top-level
handler result. Return collections inside a named `*Result` envelope; compilation
rejects collection-bearing Command/Query return types, including transitive DTO
fields and union members. Events retain
their existing collection-free payload and wire contracts.

For example, these are two separate files in an illustrative
`Application/ImportItems/` use case, not an installed business feature:

```php
<?php

declare(strict_types=1);

// ImportItemsCommand.php
namespace App\Module\TaskTracking\Application\ImportItems;

final readonly class ImportItemsCommand
{
    /** @param list<ItemInput> $items */
    public function __construct(public array $items = [])
    {
    }
}
```

```php
<?php

declare(strict_types=1);

// ItemInput.php — local to the same use case
namespace App\Module\TaskTracking\Application\ImportItems;

final readonly class ItemInput
{
    public function __construct(public string $title)
    {
    }
}
```

Register the module's `Resources/config/validation.yaml` in
`framework.validation.mapping.paths`, using native Symfony sibling constraints:

```yaml
App\Module\TaskTracking\Application\ImportItems\ImportItemsCommand:
    properties:
        items:
            - Type: list
            - Count: { max: 100 }
            - All:
                  constraints:
                      - NotNull: ~
                      - Type: App\Module\TaskTracking\Application\ImportItems\ItemInput
            - Valid: ~

App\Module\TaskTracking\Application\ImportItems\ItemInput:
    properties:
        title:
            - NotBlank: ~
            - Length: { max: 200 }
```

The structural audit supports these exact native **Default-group** forms, not arbitrary
equivalent wrappers: property `Type(list)`, finite nonnegative integer `Count(max)`,
`All` with explicit `NotNull` and the exact item `Type`, plus sibling property `Valid`
for DTO items. Ordinary named DTO edges leading to collections also require `Valid`.
Scalar, UUID, immutable-date, concrete CQRS/Input DTO and backed data-enum items are
supported. Maps, nullable items, item unions, untyped arrays, nested generic lists,
aliases/templates and recursive collection-bearing graphs are rejected. Named DTO
nesting with separately bounded lists and the empty `[]` default are allowed; an
optional promoted `@var` must agree with the constructor declaration.

`tools/Architecture/CollectionDocTypes` and `CollectionContracts` parse source
contracts; `CollectionValidationMetadata` and `CollectionValidationKernel` compare
them with loaded native metadata. `./bin/dev check` runs the structural audit
`php tools/collection-validation.php`. **phpstan/phpdoc-parser 2.3.5** is now an
explicit direct development dependency, with no package-version updates; runtime
validation needs no PHPDoc parser.

Validation has two phases: native input validation before command transaction work,
then `Platform/Messaging/ResultValidationMiddleware` validates returned DTOs before
commit. Invalid output raises the fixed internal error
`cqrs.result_validation: Handler returned invalid data.`, without violation/result
payloads; caught nested output failures still invalidate the root transaction.
Existing scalar/null/value/enum/void return contracts remain supported.

Readonly arrays are shallow, and trusted in-process code must supply ordinary owned
lists. Item limits bound accepted data, not all allocation or traversal: native
`Valid` can traverse even after a count/type failure. This is not universal deep
immutability or traversal proof. External adapters must bound bytes/items before
DTO construction; validation mappings should remain cheap and database-independent.

### Publish and handle Application events

Application handlers inject `App\Platform\Messaging\EventBus` and call
`dispatch(ApplicationEvent $event): void` during healthy owned command-handler
execution. UI, queries, Domain and ORM lifecycle callbacks cannot publish through
this API. Required invariants use explicit nested command orchestration.

| Global transport | Behavior |
| --- | --- |
| `sync://` (default) | Listeners execute immediately, **inside the producer transaction and before its final flush**. Listener commands join that transaction. Event failure invalidates the root even if caught. |
| `doctrine://default` | Dispatch stores **one native event row** on the same default DBAL connection/transaction. Producer writes and enqueue commit or roll back together. Workers use current registered handlers; listener commands own their usual root transactions. |

Sync listeners must not assume pending producer writes are SQL-visible. Async
delivery has no outer event transaction: earlier successful listener commands can
remain committed after another listener fails. Messenger retains `HandledStamp`s
for completed handlers during retries; crashes and partially completed listeners
still require module-owned idempotent commands backed by transactional uniqueness.
There is no global ordering or exactly-once external-effect guarantee.

The installed producer is
[`CreateTaskHandler`](src/Module/TaskTracking/Application/CreateTask/CreateTaskHandler.php).
It names `TaskTrackingVoter` in its permission-bearing `#[Authorize]`, constructs the
Task with its optional owner, schedules persistence, then releases Domain facts. It
translates only the Domain creation fact into the public UUID-only `TaskCreatedEvent`;
owner and completion data do not change that event's wire contract. Creation performs
no authorization grant. Event frames retain the pinned actor during synchronous delivery
but do not expose caller-derived authority.

For a new `ActivityTracking` module, the listener below goes in
`src/Module/ActivityTracking/Infrastructure/EventListener/TaskCreatedListener.php`.
First implement that module's public `RecordTaskCreationCommand(Uuid $taskId)` and
co-located idempotent handler, and register the module normally (see “Add a module”).
This example module is not installed in the template.

```php
<?php

declare(strict_types=1);

namespace App\Module\ActivityTracking\Infrastructure\EventListener;

use App\Module\ActivityTracking\Application\RecordTaskCreation\RecordTaskCreationCommand;
use App\Module\TaskTracking\Application\CreateTask\TaskCreatedEvent;
use App\Platform\Messaging\CommandBus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'application.event.bus')]
final readonly class TaskCreatedListener
{
    public function __construct(private CommandBus $commands)
    {
    }

    public function __invoke(TaskCreatedEvent $event): void
    {
        $this->commands->dispatch(new RecordTaskCreationCommand($event->taskId));
    }
}
```

The same producer/listener code works in both modes. Ordinary private, autowired,
autoconfigured listener services use the normal attribute above. Their dependencies
are limited to public data, approved immutable values and exact command/query helpers.

Domain objects may opt into `RecordsDomainEvents`/`RecordsDomainEventsTrait` under
`Platform/Event/Recording`: protected `recordDomainEvent()` collects internal facts;
public `releaseEvents()` returns and clears them. Application selects facts to
translate explicitly. This pure support adds no base entity, shared identity,
optimistic version or automatic collection. Concrete events directly extend their
empty abstract readonly category under `Platform/Event`:
`BaseEvent -> DomainEvent, ApplicationEvent, InfrastructureEvent`. All event data
and recording support are excluded from services.

Async payloads use native Symfony JSON serialization, standard `UuidNormalizer`
and microsecond-preserving `DateTimeNormalizer` configuration. UUID, immutable date
and known concrete nested-event round-trips are tested; arbitrary object-union or
polymorphic payload round-trips are not guaranteed. See
[architecture](docs/architecture.md#native-application-events-5b) for compatibility,
trust and boundary-check limits.

### Add a module

To add a module:

1. Choose `App\Module\<ResponsibilityEndingInIng>` and the structure in
   [architecture](docs/architecture.md). Create only the directories you need.
2. Add `Resources/config/services.yaml`, following TaskTracking's private,
   autowired/autoconfigured defaults. Root configuration imports these files.
   Module configuration is YAML; PHP configuration is rejected by source checks.
    `Domain`, `Infrastructure/Event`, `Resources` and the exact Application use-case data patterns
    (`Application/*/*Command.php`, `*Query.php`, `*Result.php`, `*Input.php`, `*Event.php`) are excluded from the
    service prototype. Follow the full paths in TaskTracking's configuration;
    handlers, event listeners and `UI/Console/*Command` adapters remain services. Explicitly register
    any actual Domain services you introduce. Data cannot be registered as services.
3. Add an explicit attribute mapping in `config/packages/doctrine.yaml`, under the
    default EntityManager. Entities and repository interfaces belong in `Domain`;
    Doctrine adapters belong in `Infrastructure/Persistence`. Declare `schema: 'public'` and module-prefixed table names
   (e.g. `task_tracking_*`), including join tables.
4. Register its namespace/directory in `config/packages/doctrine_migrations.yaml`.
   Migration namespace case matches the path, e.g.
   `App\Module\TaskTracking\Resources\migrations`.
5. Add co-located message/handler classes and register any module validation mapping.
   Public commands/queries require exactly one matching handler; use the approved bus
   helpers from Application/UI.
6. Run `check` and `test`. New modules automatically enter source/DI ownership
   checks; missing entity mappings, service imports and migration paths fail.

For example, after changing a TaskTracking mapping:

```sh
./bin/dev console doctrine:migrations:diff --namespace='App\Module\TaskTracking\Resources\migrations'
# Review the generated SQL, then apply it:
./bin/dev setup
```

**The namespace selects the migration's destination, not the scope of the SQL
diff.** The single EntityManager compares all mappings. Review/split generated SQL
so each migration changes only its module's data. Do not use schema-update commands
as a substitute for checked-in migrations.

Migrations use unique UTC `VersionYYYYMMDDHHMMSS` names and pending migrations sort
chronologically across namespaces. Ordinarily, keep applied migrations immutable and
introduce new changes with later versions; the comparator cannot retroactively reorder
already-executed history. The approved fresh-template-only Authorizing cleanup is an
explicit exception: it rewrites `Version20260915010000`, deletes the two unaccepted
intermediate migrations and requires database recreation rather than compatibility.
Each migration is transactional, with previous successful migrations retained if a later
one fails. Correct an unapplied failed migration and rerun setup; it never resets the
database. The setup lock serializes local checkout setup/Composer, not deployment on
multiple machines.

Mutating Doctrine migration commands use the configured default EntityManager;
`--configuration`, `--em` and `--conn` overrides are rejected to keep preflight and
execution on the same inventory/connection. Migrations never run during image
construction, cache warmup, ordinary requests or `up`.

Useful read-only validation commands:

```sh
./bin/dev console app:architecture:check             # Offline mapping/inventory
./bin/dev console app:migrations:check               # Offline migration inventory
./bin/dev console app:architecture:check --database  # Inspect actual public schema
```

## Verification and evidence

`test` builds a snapshot of the current source and locked dependencies. Application
source/vendor are read-only during tests. Each run gets unique Compose resources,
fresh credentials and separate app/runner caches. It verifies HTML/CSS, health,
source/secret isolation, database privileges, ORM persistence, migration ordering,
failure rollback/recovery, real schema-boundary failures, database outage,
container recreation with the same Task UUID/title, and readiness recovery. The host
wrapper orchestrates Docker; test containers have no Docker socket.

`check` runs on the same snapshot with an isolated runner. Both commands retain
redacted logs, exit statuses and phase timings under `var/test-runs/`. Their
containers, volumes, networks and unique image tags are cleaned up. Failed runs
retain private settings for investigation; do not publish an entire run directory.
Check runs Deptrac, the source/contract validator, the loaded collection-metadata audit
(`php tools/collection-validation.php`), compiled service checks, offline
Doctrine metadata/migration inventories and diagnostic-specific architecture tests.
It requires no running database. Check also fault-tests migration failure propagation,
Docker endpoint precedence, credential-refusal verification,
cleanup failure propagation and password-free process arguments using controlled
command stubs. Actual HTTP/PostgreSQL behavior is established separately by E2E.

`verify-setup` creates a clean source copy using Git's file/ignore list, including
intended untracked files before the first commit. It uses an automatic HTTP port
and verifies setup/repeatability, stop/start persistence, missing-settings refusal,
incomplete-settings preservation, recursive key-file exclusions using harmless
canaries, and running E2E tests alongside an ORM-persisted development Task. It checks
the same UUID/title and complete migration history after setup reruns, restarts and E2E.
The clean-model consumer journey checks the exact three default role snapshots, global
role/direct grants on the four-table schema, owner-bound Task Create/Get/List/Complete, no
automatic grant and persistence across five checkpoints. That verification passes in the
active task record; earlier consumer results cover superseded intermediate schemas.
Logs and the temporary checkout
are retained at the printed path; Docker resources are cleaned up. `TMPDIR` can
select where this temporary checkout is created.

Only the `verify-setup` consumer PTY/cookie helper additionally requires **host
Python 3**; normal setup needs no host PHP, Composer or Python. Authentication
verification includes actual hidden terminal input, native session/limiter storage,
consumer cookie isolation and log canaries collected from each container generation
after stopping it, including shutdown logs, then checked and redacted before
recreation/removal. These are checks of the exercised paths and canaries, not a proof
that arbitrary future payloads or logging integrations cannot leak.

Run all three for runtime/bootstrap changes. For other changes, run the relevant
checks and agreed E2E journey. See [Subtask 1](docs/tasks/01-runtime.md) and
[Subtask 2](docs/tasks/02-module-persistence.md) for evidence.

## Architecture and delivery

Subtasks **1, 2, 3a, 3b, 4, 5b, 6 (including the registration correction), 7, 8a, 8b and 9**
are accepted. The clean Task 10 authorization correction is implemented, verified and
freshly reviewed; user acceptance is the current gate.

Read [the architecture](docs/architecture.md), [delivery roadmap](docs/roadmap.md)
and [agent instructions](AGENTS.md). Subtask 1 establishes the executable runtime;
Subtask 2 implements module/persistence boundaries; Subtask 3a aligns public data
with Application use-case co-location. These subtasks are accepted.
[Subtask 3b](docs/tasks/03-cqrs-transactions.md#3b--approved-design-and-implementation)
implements synchronous CQRS/transactions and is also accepted; its verification,
review and acceptance evidence are in the task record.
[Subtask 4](docs/tasks/04-synchronous-events.md) and
[Subtask 5](docs/tasks/05-durable-events.md) are historical records whose delivery
designs are **superseded by [Subtask 5b](docs/tasks/05b-native-event-bus.md)**.
The accepted 5b checkpoint was verified and independently reviewed: **496 check-suite
tests / 3226 assertions** and **88 PostgreSQL E2E tests / 1270 assertions** passed,
alongside fresh consumer verification. The user accepted 5b on 2026-09-13.
Subtask 6, including the approved registration correction, is **VERIFIED, REVIEWED
and USER ACCEPTED on 2026-09-13**. Post-correction `./bin/dev setup` passed with
dependencies/migration unchanged and app/database healthy. `./bin/dev check` passed
**610 tests / 3942 assertions**, Deptrac **1031 allowed / 0 violations / 0 uncovered**,
at `var/test-runs/run-eTQK6EAr/`. `./bin/dev test` passed **137 tests / 1991 assertions**,
all 21 PHPUnit phases, at `var/test-runs/run-xaiXVV3r/`. Fresh consumer verification
passed in `/tmp/opencode/donmario-setup-fej1lSca/`, with embedded **137 tests / 1990
assertions** at `application/var/test-runs/run-1pN3Ocj1/`. Fresh independent correction
reviewer `ses_f64339484ffeQjNteclNnjAlvj` inspected code/evidence and **APPROVED** with
no concrete findings; the earlier two approvals cover the unmodified native web-authentication scope.
**Pre-correction historical evidence:** `check` **588 tests / 3738 assertions** at
`var/test-runs/run-hsi0IoyH/`; PostgreSQL E2E **120 tests / 1744 assertions** at
`var/test-runs/run-qeu9uWzb/`; consumer `/tmp/opencode/donmario-setup-RBWCh0mQ/`
passed with embedded **120 tests / 1745 assertions**. Both earlier reviewer rechecks
approved that snapshot; the correction's fresh approval is recorded above. [The task record](docs/tasks/06-web-authentication.md)
owns exact evidence.

**Subtask 7 final verification (2026-09-13):** `./bin/dev setup` passed retaining
RSA3072 keys, with dependencies unchanged, migration current and app/database healthy.
`./bin/dev check` passed **695 tests / 4385 assertions**, Deptrac **1246 allowed /
0 violations / 0 uncovered**, at `var/test-runs/run-VU1J5W0M/`. `./bin/dev test`
passed **201 tests / 4606 assertions**, all **38 PHPUnit phases**, at
`var/test-runs/run-Dk8kGEH0/`. `TMPDIR=/tmp/opencode ./bin/dev verify-setup` passed in
`/tmp/opencode/donmario-setup-tr7xoQb4/`, with embedded **201 tests / 4607 assertions**,
all 38 phases, at `application/var/test-runs/run-KkpCT8uO/`. Fresh authentication
reviewer `ses_f63a1e74affeszKsYM4RJDMnZS` and runtime reviewer
`ses_f63a1e72bffePxCpnh11QTnL9Q` **APPROVED** after inspecting code/evidence; they did
not rerun suites. [Task 7](docs/tasks/07-jwt-authentication.md) records exact coverage,
review conclusions and local timing observations. **IMPLEMENTED, VERIFIED, REVIEWED
and USER ACCEPTED on 2026-09-14.** This evidence is historical for Subtask 7.
**Subtask 8a is IMPLEMENTED, VERIFIED, REVIEWED and USER ACCEPTED on 2026-09-15** under
the approved [8a/8b design](docs/tasks/08-authorizing.md). Verification and reviews
completed on 2026-09-14. Setup passed; check passed
**822 tests / 4731 assertions**, standalone E2E **202 tests / 4673 assertions**, and
consumer E2E **202 tests / 4676 assertions**. Both fresh independent implementation
reviewers approved with no findings and did not run suites. See the
[handoff evidence](docs/handoff.md#completed-8a-verification-and-review--2026-09-14)
for exact paths and final-code coverage. Earlier fixture YAML/static issues are resolved.
8a was pushed as `367fdfe` on 2026-09-15. **8b is IMPLEMENTED, VERIFIED, REVIEWED and
USER ACCEPTED** on that date. Final 8b runs cover the earlier cursor
Domain-exception and EXPLAIN numeric fixes, N=100 budgets, populated plans and
consumer persistence/isolation. Both fresh independent reviewers approved with no
findings; neither reran suites. Only documentation changed between 8b review and acceptance.
See [final handoff evidence](docs/handoff.md#completed-8b-verification-and-review--2026-09-15).
The subsequent “accept and proceed” approved Task 9's full policy-only design.
Task 9 is implemented, verified, freshly independently reviewed and user accepted
on 2026-09-16, including the enum correction. See its [task record](docs/tasks/09-authorization-enforcement.md) for final
evidence and resolved intermediate failures. This authorized delivery includes 8b/9;
future commits/pushes need explicit authorization. The initializer retains its later approval gate.

For dependency updates, use containerized Composer, review recipe/lock changes,
refresh image digests and CLI archive hashes deliberately, then run the checks
above. Symfony 8.1's maintenance window ends in January 2027; track upgrades as
part of template maintenance. Base-image pinning does not freeze the Debian
package repository used during image construction.
