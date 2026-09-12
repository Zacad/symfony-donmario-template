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

Setup installs `composer.lock`, generates local credentials once, starts PostgreSQL,
validates/applies pending module migrations, and waits for the application. It can
be rerun without replacing credentials or resetting the database. A migration
failure returns nonzero before the success message. Symfony was originally scaffolded using Symfony CLI;
ordinary checkout setup uses that existing application.

## Development commands

| Command | Purpose |
| --- | --- |
| `./bin/dev setup [port]` | Build images, install dependencies, migrate and start |
| `./bin/dev up` | Start services and wait for readiness |
| `./bin/dev down` | Stop services, retaining persistent volumes |
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
- **Changed host UID/GID:** stop this checkout, update `LOCAL_UID`/`LOCAL_GID` to
  your current IDs, and recreate only its disposable `PROJECT_runtime` cache/log
  volume before `setup`. Repair ownership of your source/vendor files using your
  normal host administration process. PostgreSQL retains its own UID and data volume.
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
data restrictions and [Subtask 4](docs/tasks/04-synchronous-events.md) for current status.

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

### Synchronous events

Subtask 4, including opt-in recording, is **implemented, verified, independently
reviewed and accepted by the user**. Required effects use explicit nested commands
inside the root transaction. Public Application events are delivered best effort,
synchronously **after confirmed commit and root ORM/context cleanup**. Each listener
command has a fresh independent transaction. Listener failures retain producer success
and earlier committed effects, log safe metadata and allow remaining delivery to continue.

`Task` records a Domain TaskCreatedEvent; its handler maps it to the public
`Application/CreateTask/TaskCreatedEvent` with the Task UUID and records it through
the Application-only `Platform/Messaging/ApplicationEventRecorder`. Recording is
restricted to healthy owned command-handler execution, never queries or ORM lifecycle
callbacks. See architecture for the stack-based detection's exact limitations.

Domain objects opt into recording through `RecordsDomainEvents` and
`RecordsDomainEventsTrait` under `Platform/Event/Recording`. The trait provides
protected `recordDomainEvent()` and public `releaseEvents()`, which returns and
clears the object's pending Domain facts. Application explicitly selects facts to
translate. This capability adds no base entity, shared ID mapping, optimistic version
or automatic collection; the interface/trait are excluded from Symfony services.

Concrete events directly extend their exact empty abstract readonly category under
`Platform/Event`: `BaseEvent -> DomainEvent, ApplicationEvent, InfrastructureEvent`.
These are data-only exceptions, not general Domain access to Platform. Primitives
contain no event IDs or metadata. Our internal `Infrastructure/Event/*Event` and
private `Infrastructure/EventListener/*Listener` are distinct from vendor adapters
under `Infrastructure/Framework/<Library>/EventListener`. Our listeners receive exact
public events and dispatch commands/queries through the approved helpers.

FIFO delivery accepts at most 100 events per root buffer and 100 per delivery session;
valid overflow is dropped and diagnosed. Caps do not bound payload bytes or handler
runtime. Cleanup failure disables further messaging/recording on that runtime and
skips unsafe delivery while preserving an already committed producer result. Logging
failure cannot replace that result. There is no outbox, durable retry or replay;
process failure may lose in-memory events. The `EventObserving` subscriber module is
a disposable test fixture, not a production business module.

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

Run all three for runtime/bootstrap changes. For other changes, run the relevant
checks and agreed E2E journey. See [Subtask 1](docs/tasks/01-runtime.md) and
[Subtask 2](docs/tasks/02-module-persistence.md) for evidence.

## Architecture and delivery

Read [the architecture](docs/architecture.md), [delivery roadmap](docs/roadmap.md)
and [agent instructions](AGENTS.md). Subtask 1 establishes the executable runtime;
Subtask 2 implements module/persistence boundaries; Subtask 3a aligns public data
with Application use-case co-location. These subtasks are accepted.
[Subtask 3b](docs/tasks/03-cqrs-transactions.md#3b--approved-design-and-implementation)
implements synchronous CQRS/transactions and is also accepted; its verification,
review and acceptance evidence are in the task record. [Subtask 4](docs/tasks/04-synchronous-events.md)
implements the approved revised layered events and postcommit delivery design. Full
verification and fresh independent reviews are complete, with all findings resolved,
and the user accepted the result including the recording refactor. Next is separate
Subtask 5 discovery/design, with approval required before implementation.
Durable/async events, authentication, authorization and the reusable initializer
retain their subsequent approval gates.

For dependency updates, use containerized Composer, review recipe/lock changes,
refresh image digests and CLI archive hashes deliberately, then run the checks
above. Symfony 8.1's maintenance window ends in January 2027; track upgrades as
part of template maintenance. Base-image pinning does not freeze the Debian
package repository used during image construction.
