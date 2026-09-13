# Subtask 5b — simplify events to native Messenger transport switching

## Approval and current status

The user rejected the custom durable-subscription framework as overengineered and
requested `$eventBus->dispatch($event)` with a global sync/async transport switch.
They explicitly accepted synchronous listeners running **inside the producer's
transaction**, then approved the concrete simplification plan with **“proceed”**.

Implementation, container/PostgreSQL verification and fresh independent reviews are
complete, with all findings resolved. **The user accepted Subtask 5b on 2026-09-13
with “i accept” and requested a commit/push followed by a fresh session for the
next subtask.** This supersedes the postcommit best-effort delivery and
custom durable-subscription designs in tasks 4/5; their evidence is historical.

## Approved scope

- One Application-only thin EventBus with `dispatch(ApplicationEvent): void`, backed
  by the ordinary `application.event.bus`. Keep standard private listener attributes.
- One `events` transport selected by `EVENT_TRANSPORT_DSN`: `sync://` by default or
  `doctrine://default`. Application code and listeners do not select the mode.
- Sync dispatch executes immediately and listener commands join the command's
  existing transaction. A dispatch failure invalidates that transaction even when
  caught, consistent with nested command/query failures. No postcommit buffering.
- Async dispatch queues one native event on the same default DBAL connection and
  transaction. Workers use current listeners; each listener command owns its usual
  command transaction. Normal Messenger retries/failure handling, partial success
  and idempotent consumers apply. No outer worker event transaction.
- Symfony JSON serialization and required Symfony normalizer/property components.
  Verify UUID/date/nested supported payloads. Preserve only small necessary
  diagnostics safeguards; standard failed-message retry may run handlers inline.
- Remove recorder, durable publisher, subscription IDs/tags/registry, private jobs,
  custom wire protocol, buffers, hop/byte/job budgets and their exhaustive checks.
- Keep optional Domain-event recording and explicit Application translation,
  command/query transaction coordination and useful module boundaries.
- Keep the already-applied exact Platform queue migration/table. Use distinct native
  `events` / `events_failed` queue names. No automatic conversion or deletion of old
  rows. Drain old jobs using compatible code before removing that runtime.
- Consolidate event tests around one small subscriber fixture in both modes.

## Security and performance review

Private listener/data/module boundaries and protected local database credentials
remain. Native JSON uses trusted queue writers and is not the former custom
allowlist/digest-only wire protocol. Small diagnostics adapters must avoid payload,
credential and arbitrary error dumps while retaining native retry semantics.
Sync listeners hold the producer transaction open and run before its final flush;
they must not assume pending writes are already visible through SQL. Async processing
is at least once; participating module commands require idempotency backed by
database uniqueness. No global order or exactly-once external-effect guarantee.
Normal worker reset/recycling applies; no custom delivery-budget framework.

## Acceptance criteria

Actual containers/PostgreSQL must prove:

1. Sync HTTP/CLI producer and listener-command writes share commit/rollback, including
   caught dispatch failure and final flush failure, with held-service recovery.
2. Async producer and one native event row commit atomically; another connection
   cannot consume before commit; enqueue/flush failure leaves neither.
3. Identical producer/listener code works with both DSNs. Mode selection reaches
   HTTP, CLI and workers; workers refuse a synchronous transport without restart loops.
4. Native JSON UUID/date/supported payload round-trips, current-listener handling,
   partial failure/retry exhaustion, safe operator diagnostics, duplicate/crash
   idempotency, real Compose worker and PostgreSQL outage/recovery.
5. CQRS/module/persistence regressions and fresh consumer setup/isolation pass.
6. Fresh independent review findings are resolved and affected behavior reverified.

## Preflight evidence

`./bin/dev console dbal:run-sql 'SELECT queue_name, COUNT(*) AS jobs FROM public.platform_messaging_message GROUP BY queue_name'`
exited **0** and returned an empty result set. The development queue contains no
pending, leased or failed legacy rows to drain. Existing development business data,
settings and applied migration history are preserved.

Required verification after implementation:

```sh
./bin/dev setup
./bin/dev check
./bin/dev test
TMPDIR=/tmp/opencode ./bin/dev verify-setup
```

## Completed pre-review verification

All commands below exited **0**:

| Command | Result and meaningful behavior | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | Dependencies installed, applied queue migration retained/current, development PostgreSQL and HTTP healthy in default sync mode | Console: `http://127.0.0.1:8080` |
| `./bin/dev check` | **480 architecture tests / 3106 assertions + 8 diagnostics tests / 104 assertions** (**488 / 3210 total**); Deptrac **746 allowed / 0 violations / 0 uncovered**; audit, lint, PHPStan, Symfony style and shell contracts pass; fixture compiles in both modes | `var/test-runs/run-zYvt3rm8/` |
| `./bin/dev test` | **88 tests / 1270 assertions** across phases; same fixture in sync and async, HTTP/CLI mode switching with warm caches, atomic enqueue/rollback, native retry/partial HandledStamps, producer/worker SIGKILL, real Compose worker, PostgreSQL recreation/recovery and untouched legacy queue row | `var/test-runs/run-sfF0aTVT/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Fresh consumer setup/repeatability, current native event journeys in both modes, build-context/settings isolation and development marker/history preservation pass | `/tmp/opencode/donmario-setup-ERzZAYfG/` |

`./bin/dev worker start` in sync mode exited **1** before starting a worker, with
the expected instruction to export the async DSN. The raw native consume command
silently excludes sync receivers; use the supported wrapper.

Installed native serialization support through:
`./bin/dev composer require 'symfony/property-access:8.1.*' 'symfony/property-info:8.1.*' --no-interaction --no-scripts`.
Resolved PropertyAccess **8.1.4**, PropertyInfo **8.1.6**, TypeInfo **8.1.5**;
reviewed Flex's constructor-extractor recipe. No custom wire codec remains.

Initial nonpassing runs were corrected:
- `run-sAQgIBZ7`: PHPUnit 13 requires repeated group options, not comma-separated groups.
- `run-hDywQDmD`: explicit fixture schema and native sync receiver skip expectation.
- `run-TVdKgkH3`: PostgreSQL savepoints can give queue rows distinct `xmin`; atomicity
  is established by actual precommit visibility, independent receiver and rollback.
- `run-bW6AKRqy`: the disposable fixed-cache kernel now discards its cache explicitly
  before testing changed listener inventory, avoiding cache-clear warmup staleness.
- `/tmp/opencode/donmario-setup-O6MjVVPB`: consumer export now honors working-tree
  deletions as well as untracked additions, without modifying the Git index. The
  subsequent clean-consumer run passed.

Mode-specific tests run only in applicable phases; the passing full E2E has no
skipped/blocked journeys. The consumer includes the latest fixture/export corrections.
Evidence directories can contain private settings: share redacted logs only.

## Independent review and final evidence

- Runtime reviewer `ses_f66723552ffeiiqFu07PtgJioq`: approved, no blocking runtime
  issues. Corrected two documentation findings: final verification status and the
  distinction between disabled notification-based receiving and native sends that
  still emit `pg_notify`.
- Boundary/tooling reviewer `ses_f6672353cffef9Hmb6yF1hRqj8`: approved after the
  correction below; no remaining actionable findings. Fixture instructions now use
  PHPUnit 13's repeated group flags and include the async-specific group.

The boundary review found a same-module DI bypass: a UI/Infrastructure service
could receive a listener through an alias and invoke it outside Messenger. The
resolved dependency checker now rejects listener targets consumed by module
services, including closure/locator/inline wrappers. Native framework descriptor
wiring remains valid. Eight targeted negative cases verify the correction.

Final affected command:

`./bin/dev check` — **496 tests / 3226 assertions** (488 architecture / 3122 plus
8 diagnostics / 104), Deptrac **747 allowed / 0 violations / 0 uncovered**, all
lint/audit/PHPStan/style/shell checks and native fixture compilation in both modes
pass. Evidence: `var/test-runs/run-KOAAh7lo/`.

This correction changes only container-boundary enforcement and its tests; runtime,
tooling and native E2E files match the successful consumer snapshot. The prior
**88 tests / 1270 assertions** PostgreSQL run and fresh consumer verification remain
applicable, as confirmed by the independent reviewer. No unchanged passing runtime
suites were repeated for final documentation alignment.

Subtask 5b is accepted. Next is separate **Subtask 6: Authenticating web**
discovery/design in a fresh session. Its design and implementation are not approved;
present acceptance criteria and security/performance review before requesting approval.
