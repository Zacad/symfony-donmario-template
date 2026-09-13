# Session handoff — native Messenger EventBus

Date: **2026-09-13**

Repository: `/var/home/adam/Projects/symfony-donmario-template`

## Status and next gate

The user requested a simpler `$eventBus->dispatch($event)` API and global native
Messenger transport switching, explicitly accepting sync listeners executing
**inside the producer transaction**. They approved Subtask 5b with **“proceed”**.

**Subtask 5b is implemented, verified using actual containers/PostgreSQL, and
independently reviewed with all findings resolved. The user accepted it on
2026-09-13 with “i accept” and requested commit/push.**

**Next in a fresh session: Subtask 6 — Authenticating web discovery/design.**
Inspect the existing security dependencies and application paths, then propose
account provisioning, login/logout, throttling and CSRF with acceptance criteria
and security/performance review. Obtain design approval before implementing it.
JWT authentication and authorization retain their later separate approval gates.

Subtasks 1, 2, 3a, 3b and 4 were accepted. Historical task 4 postcommit delivery and
task 5 custom durable subscriptions are superseded by 5b. Their records are retained
as historical evidence, not current operating instructions.

## Read first

1. `AGENTS.md`, `README.md`, `docs/architecture.md`, `docs/roadmap.md`.
2. `docs/tasks/05b-native-event-bus.md` — approved brief, implementation/evidence,
   verification corrections and independent review results.
3. Composer/Flex manifests and locks; inspect current Git status before editing.

This handoff accompanies the accepted native-event checkpoint on `main`, following
`8f4f3f9`. The user requested committing and pushing the accepted implementation and
handoff to `origin` (`https://github.com/Zacad/symfony-donmario-template.git`). Inspect
`git status`, recent history and remote tracking on resumption rather than assuming
uncommitted work. Preserve the instruction to use subagents for independent work.
Further commits/pushes require their own explicit request.

## Current API and delivery

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

| Command | Result / evidence |
| --- | --- |
| `./bin/dev setup` | Passed; existing queue migration current, HTTP/PostgreSQL healthy in default sync mode |
| `./bin/dev check` | **496 tests / 3226 assertions**, Deptrac **747 allowed / 0 violations / 0 uncovered**, all checks and both-mode native fixture compilation pass; `var/test-runs/run-KOAAh7lo/` |
| `./bin/dev test` | **88 tests / 1270 assertions**, both modes, rollback/enqueue visibility, current handlers/native partial retries, SIGKILL recovery, real Compose worker, legacy-row preservation and database recreation; `var/test-runs/run-sfF0aTVT/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Passed; fresh consumer setup/repeatability, same two-mode journeys, settings/build-context/dev-test isolation and development marker/history preservation; `/tmp/opencode/donmario-setup-ERzZAYfG/` |

The final review correction rejects declared DI injection of event listeners into
module services, including same-module aliases/closures/locators/inline wrappers.
Eight negative cases and full checks/both-mode compilation pass. Runtime/tooling/E2E
were unchanged, so earlier PostgreSQL/consumer evidence remains applicable.

Fresh independent reviewers:
- Runtime `ses_f66723552ffeiiqFu07PtgJioq` — approved; documentation findings corrected.
- Boundaries/tooling `ses_f6672353cffef9Hmb6yF1hRqj8` — approved after correction.

Native JSON round trips prove UUID, immutable dates with microseconds and known
concrete nested event payloads. Do not infer arbitrary object-union/polymorphic
support. PropertyAccess 8.1.4, PropertyInfo 8.1.6 and TypeInfo 8.1.5 were installed
through container Composer; the constructor-extractor Flex recipe was reviewed.

Initial integration failures and resolutions are recorded in task 5b. The consumer
export now honors tracked working-tree deletions as well as untracked additions
without modifying the Git index. Do not rerun unchanged passing suites merely to
resume. Use `./bin/dev` for all relevant PHP/Composer/testing commands.

Development remains in sync mode with app/database running and worker stopped.
Preserve local credentials, volumes and applied migrations. Evidence directories
may contain private settings; share only redacted logs. Tests must use isolated data.

## Fresh-session prompt

> Read `docs/handoff.md`, `AGENTS.md`, `README.md`, `docs/architecture.md`,
> `docs/roadmap.md` and `docs/tasks/05b-native-event-bus.md`. Subtask 5b is accepted.
> Begin Subtask 6 web-authentication discovery and propose a bounded design with
> acceptance criteria and security/performance review. Obtain approval before
> implementation. Preserve the native EventBus and module/CQRS transaction boundaries.
