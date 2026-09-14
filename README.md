# DonMario Symfony application template

A Docker-first foundation for building applications with AI-assisted development.
Licensed under [MIT](LICENSE), copyright DonMario.

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
reviews completed on 2026-09-13. Next fresh session: **Subtask 8 — Authorizing:
model/management DISCOVERY/DESIGN ONLY**, with a bounded design, acceptance criteria
and explicit security/performance review before implementation approval.
LexikJWTAuthenticationBundle **3.2.0**, Lcobucci JWT **5.6.0** and API Platform Symfony
**4.3.19** are installed. Business APIs and Authorizing are later subtasks.

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

The initial `TaskTracking` module contains a UUID/title Task, its repository port/adapter and
the `public.task_tracking_task` migration. UUIDs are generated by Symfony UID;
PostgreSQL uses its native `uuid` type without an extension. The create/read use cases
share synchronous Messenger command/query buses across HTTP and CLI.

Repository contracts belong to the module's Domain:

```text
TaskTracking/Domain/Task.php
TaskTracking/Domain/TaskRepository.php
TaskTracking/Infrastructure/Persistence/DoctrineTaskRepository.php
```

`TaskRepository` exposes `add(Task): void` and `find(Uuid): ?Task`.
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
```

Subtask 3b implements this example on the boundary rules established in 3a. The exact
`Application/<UseCase>/<Name>{Command,Query,Result,Event}` types form the public data API;
neighboring handlers/helpers remain private module implementation. Domain is
independent of Application DTOs and public events. Subtask 4 adds internal
`Domain/Event/*Event` facts explicitly translated by Application to public events;
public payloads cannot carry command/query/result DTOs or module internals. There is
no current `Contract` directory. See [architecture](docs/architecture.md) for exact
data restrictions and [Subtask 5b](docs/tasks/05b-native-event-bus.md) for the active
native-event design and verification status.

`add()` schedules persistence; flushing and committing belong to the application
transaction boundary. The entity uses Doctrine mapping attributes but never names
an infrastructure repository through `repositoryClass`. Domain/Application source
cannot depend on runtime Doctrine/PDO APIs or outward module layers. Compiled DI
accepts an infrastructure adapter through a declared, same-module Domain interface
and rejects direct concrete injection, including through named aliases.

### Try the shared use cases

```sh
./bin/dev console app:task:create 'First task'
# Use the printed UUID:
./bin/dev console app:task:show UUID

curl -i -H 'Content-Type: application/json' \
    -d '{"title":"Created over HTTP"}' http://127.0.0.1:8080/_demo/tasks
curl http://127.0.0.1:8080/_demo/tasks/UUID
```

These HTTP demonstration routes exist in **dev/test only**. POST returns 201 with
the UUID and Location; GET returns UUID/title JSON or 404. Payload-shape errors are
400, bodies above 4 KiB are 413, non-JSON POSTs are 415 and message validation is 422.
CLI message validation exits 2, missing tasks/operation failures exit 1, and success
exits 0. Native command-line syntax errors use Symfony Console's diagnostics.
Responses use no-store; unexpected failures expose only a generic error.

Application/UI adapters inject `App\Platform\Messaging\CommandBus` or `QueryBus`.
The outer command validates, starts the default Doctrine transaction, runs its
handler, flushes and commits before returning. Nested commands share that unit;
a nested failure prevents the outer commit even if caught. Independent operations
reset ORM/context state. Queries never automatically flush, and cannot dispatch
commands. Repositories continue to schedule persistence without flushing.

Module-owned `Resources/config/validation.yaml` files must be explicitly listed in
`framework.validation.mapping.paths`. Handlers use `#[AsMessageHandler(bus: ...)]`
with one exact DTO argument and a public-data return type, beside their message.
Compilation rejects missing/duplicate/wrong-bus handlers and invalid middleware
wiring. Raw Messenger services are internal infrastructure, not module-facing APIs.
The standard framework messages visible in `debug:messenger` are rejected by the
application message policy; command/query helpers dispatch only their inventoried DTOs.

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

The installed producer explicitly translates selected Domain facts:

```php
<?php

declare(strict_types=1);

namespace App\Module\TaskTracking\Application\CreateTask;

use App\Module\TaskTracking\Domain\Event\TaskCreatedEvent as DomainTaskCreatedEvent;
use App\Module\TaskTracking\Domain\Task;
use App\Module\TaskTracking\Domain\TaskRepository;
use App\Platform\Messaging\EventBus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler(bus: 'command.bus')]
final readonly class CreateTaskHandler
{
    public function __construct(private TaskRepository $tasks, private EventBus $events)
    {
    }

    public function __invoke(CreateTaskCommand $command): Uuid
    {
        $task = new Task($command->title);
        $this->tasks->add($task);
        foreach ($task->releaseEvents() as $event) {
            if ($event instanceof DomainTaskCreatedEvent) {
                $this->events->dispatch(new TaskCreatedEvent($event->taskId));
            }
        }

        return $task->id();
    }
}
```

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
    (`Application/*/*Command.php`, `*Query.php`, `*Result.php`, `*Event.php`) are excluded from the
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
chronologically across namespaces. Keep applied migrations immutable and introduce
new changes with later versions; the comparator cannot retroactively reorder
already-executed history. Each migration is transactional, with previous successful
migrations retained if a later one fails. Correct an unapplied failed migration
and rerun setup; it never resets the database. The setup lock serializes local
checkout setup/Composer, not deployment on multiple machines.

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
Check runs Deptrac, the source/contract validator, compiled service checks, offline
Doctrine metadata/migration inventories and diagnostic-specific architecture tests.
It requires no running database. Check also fault-tests migration failure propagation,
Docker endpoint precedence, credential-refusal verification,
cleanup failure propagation and password-free process arguments using controlled
command stubs. Actual HTTP/PostgreSQL behavior is established separately by E2E.

`verify-setup` creates a clean source copy using Git's file/ignore list, including
intended untracked files before the first commit. It uses an automatic HTTP port
and verifies setup/repeatability, stop/start persistence, missing-settings refusal,
incomplete-settings preservation, recursive key-file exclusions using harmless
canaries, and running E2E tests alongside an ORM-persisted development Task. It verifies
the same UUID/title and complete migration history after setup reruns, restarts and E2E.
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

Subtasks **1, 2, 3a, 3b, 4, 5b, 6 (including the registration correction) and 7** are accepted.

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
and USER ACCEPTED on 2026-09-14.** Subtask 8 has not started; its discovery/design is
reserved for the next fresh session. Authorization and the initializer retain their
later approval gates.

For dependency updates, use containerized Composer, review recipe/lock changes,
refresh image digests and CLI archive hashes deliberately, then run the checks
above. Symfony 8.1's maintenance window ends in January 2027; track upgrades as
part of template maintenance. Base-image pinning does not freeze the Debian
package repository used during image construction.
