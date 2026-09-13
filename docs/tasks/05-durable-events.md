# Subtask 5 — durable optional event delivery

**Historical design, superseded by approved [Subtask 5b](05b-native-event-bus.md).**
The user requested native Messenger transport switching and sync listeners inside
the producer transaction. The old delivery framework below was replaced; this
record retains its historical decisions/evidence, not current usage instructions.

## Approval and status

The user approved the proposed design with **“proceed”**, selecting **module-owned
idempotent commands** for duplicate handling. Implementation and container/PostgreSQL
verification and fresh independent reviews are complete, with all findings resolved.
The user subsequently chose the simpler native transport design and accepted
**Subtask 5b on 2026-09-13**; this custom delivery design is superseded. Initial delegated
implementation calls were cancelled without changes; work resumed after the user
requested a retry.

## Approved design

- Individual subscriptions opt into worker delivery through module YAML and stable
  subscription IDs. Other subscriptions retain accepted synchronous best-effort
  behavior. Recipients are frozen at publication.
- Symfony 8.1 Doctrine Messenger uses PostgreSQL as the transactional outbox,
  on the exact default DBAL connection inside the producer's ORM transaction.
  One job per durable subscription is enqueued before implicit flush/commit.
- Private envelopes carry publication/subscription identity, event type/version
  and payload. Event category primitives remain empty.
- At-least-once consumer attempts require module-owned idempotent commands,
  transactional database uniqueness and meaningful duplicate/concurrency tests.
  Every independently committed step must tolerate whole-listener replay.
- A private worker bus propagates failures to standard retries/failure transport.
  Three exponential-backoff retries; failed jobs persist until explicit replay or
  removal. Replay preserves logical identity. Poisoned runtime state stops workers.
- Exactly one Platform-owned transport table and migration; no receipt table.
  Technical schema and DI exceptions are exact and checked, not broad exemptions.
- Allowlisted versioned JSON via Symfony Serializer and a narrow transport adapter.
  Safe diagnostics and persisted error metadata omit payloads, arbitrary exception
  messages, SQL and credentials.
- Durable limits: 100 jobs/root, 64 KiB/job, 1 MiB/root and 16 durable cascade hops.
  Violations roll back their producing transaction rather than silently dropping
  durable obligations. Existing synchronous-only overflow behavior is retained.
- Optional explicit container worker commands; sequential execution and per-job
  reset. No global ordering or exactly-once external-effect guarantee.

## Security and performance review

Workers retain nonsuperuser application credentials and dev/test isolation. No
arbitrary PHP deserialization or client-selected subscriptions. Wire types and
versions are allowlisted; malformed deliveries receive safe failure handling.
Queue indexes, bounded polling, publication limits and worker recycling bound
individual operations; failed-job storage requires explicit cleanup. Required
business/security effects remain explicit nested commands. Subscription changes
need drain/compatibility procedures. Consumer idempotency is a tested application
contract, not something static checks can infer.

## Acceptance and verification

Actual isolated containers/PostgreSQL must prove HTTP/CLI atomic publication,
producer rollback and crash boundaries, worker crash-after-effect-before-ack,
duplicate/concurrent attempts, retry/failure/replay, database outage/recreation,
safe malformed-job diagnostics, and synchronous/architecture regressions.

Required commands:

```sh
./bin/dev setup
./bin/dev check
./bin/dev test
TMPDIR=/tmp/opencode ./bin/dev verify-setup
```

Record exact results and meaningful observations here. Fresh independent review
and resolution/reverification of findings precede user acceptance.

## Implementation details

`ApplicationEventRecorder` captures frozen per-subscription jobs independently of
the synchronous buffer. `DurablePublisher` enqueues them inside the root retained
ORM callback, after handler execution and before implicit flush. The codec is
allowlisted v1 JSON using Symfony Serializer's JsonEncoder; Doctrine Messenger and
Serializer **8.1.6** were installed without changing other package versions. Flex
added no recipe files for these packages. Private `durable.event.bus` receives only
DeliveryJob and digest-only InvalidDeliveryJob. Concrete event category classes and
module-facing producer APIs retain their accepted roles.

The transport table is `public.platform_messaging_message`, created by the exact
`App\Platform\Messaging\Resources\migrations\Version20260912010000` migration.
Both queues use the default DBAL connection, without automatic schema setup or
PostgreSQL notifications. Retries use 1/2/4-second delays. For retry/failure metadata,
the 64-KiB job ceiling reserves 1,024 bytes: canonical content without stamps may
occupy at most 64,512 bytes. The root total counts initially encoded full bodies.

Safe failure show/retry/remove commands replace stock verbose command implementations.
Replay is atomic enqueue plus failed-row ACK on the same connection, preserves the
logical message and starts a fresh retry budget; it never executes the subscriber
synchronously. Malformed input retains only a fixed reason/digest representation.
Worker exceptions, logging and console error output are sanitized. Module-owned
idempotent commands, including every independent step, remain mandatory.

The configured worker's 300-second lease has no keepalive or hard handler timeout.
Process time/memory/message limits are soft between jobs; long overlapping delivery
must remain safe through idempotency. See architecture for guarantees and limits.

## Verification evidence

All completed commands below exited **0**:

| Command | Result / observable behavior | Evidence |
| --- | --- | --- |
| `./bin/dev setup` | New transport migration applied; PostgreSQL and HTTP healthy; PCNTL-enabled runtime builds | Application ready at `http://127.0.0.1:8080` |
| `./bin/dev check` | **588 architecture tests / 4252 assertions + 43 unit tests / 530 assertions**; Deptrac **1029 allowed / 0 violations / 0 uncovered**; audit, lint, PHPStan max, Symfony style and shell contracts pass | `var/test-runs/run-nnO13i9b/checks.log` |
| `./bin/dev test` | **116 tests / 2701 assertions**, including 21 durable tests / 1026 assertions, separate Compose worker and outage/recovery | `var/test-runs/run-0sxLuexq/` |
| `TMPDIR=/tmp/opencode ./bin/dev verify-setup` | Fresh consumer setup/repeatability and full E2E **116 tests / 2703 assertions**, preserving development marker, history and settings; worker test volume cleanup confirmed | `/tmp/opencode/donmario-setup-gDGG1bRy/` |
| `./bin/dev worker start && ./bin/dev worker status && ./bin/dev worker stop && ./bin/dev worker status` | Actual development worker starts, reports running, gracefully exits 0 and reports stopped | Console output |

The consumer run includes the final barrier/lease fixture correction and explicit
worker-profile resource cleanup. Its exact E2E directory is
`application/var/test-runs/run-J1GKIMml/` under that consumer evidence directory.
Assertion counts can vary with bounded barrier polling.

Established behavior includes real HTTP/CLI publication with no inline durable
effect, producer rollback after queue insertion, SIGKILL before/after commit,
worker death after effect commit before ACK, concurrent uniqueness conflicts,
no duplicate downstream obligations, retry exhaustion, metadata-only replay,
payload/size/count/hop bounds, same held-service recovery, actual postcommit
registry cleanup failure stopping the worker, malformed-job safe failure and
recovery, and pending-job survival across PostgreSQL container recreation.

Initial failed verification runs were resolved, not counted as passing evidence:
- `run-Lt0KLAR1`: codec static type narrowing; runtime tests had passed.
- `run-3xPWA6hy`: size fixtures still assumed the pre-reserve ceiling; corrected to
  prove 64,512 canonical content bytes and exact 1-MiB initial bodies with 19 jobs.
- `/tmp/opencode/donmario-setup-j1X11zx4`: crash fixture could process the child
  before intended redelivery. The test now holds that child and expires the original
  lease well beyond the strict cutoff. The subsequent full consumer run passed.
- The first Compose worker smoke exposed omitted profile-volume cleanup; test and
  consumer cleanup now include the worker profile. The previous test-only orphan
  volume was explicitly removed and subsequent cleanup was observed.

Evidence directories may contain private settings. Share only redacted logs, never
whole directories. Fresh independent reviews and their resolutions follow below.

## Independent review and corrections

Fresh reviewers `ses_f68f14ef3ffewLSWNamP8X1bF5` (runtime) and
`ses_f68f14ea0ffeEGNiQLR1NW07wc` (boundaries/tooling) requested five corrections:

1. **Forged failure stamp bypassed failure retention.** On active-queue failure,
   remove incoming SentToFailureTransportStamp before Symfony retry/failure listeners.
   Real workers now retain failure-stamped invalid, unknown and exhausted deliveries
   while continuing healthy jobs; the original row is not silently discarded.
2. **Concurrent operator commands could replay a stale failed row.** Retry/remove
   now lock and verify the exact failed row on the default DBAL connection before
   lookup/send/ACK. PostgreSQL lock barriers prove retry/retry and retry/remove races
   have exactly one successful operator. Actual INSERT and DELETE trigger failures
   prove atomic rollback and retained failed rows; replay gets a fresh retry budget.
3. **Unshared default connection could break replay atomicity.** Compilation now
   validates canonical shared DBAL lifecycle/factory/configuration and EntityManager
   wiring. A positive compiled-object test proves the EntityManager, both transports
   and replay command use the identical unopened DBAL object.
4. **An unlogged transport table passed schema validation.** Require permanent
   logged PostgreSQL storage (`relpersistence = 'p'`), with an actual transactional
   `ALTER TABLE ... SET UNLOGGED` negative/recovery case.
5. **Retry listener could be disconnected from the checked strategy.** Validate
   effective listener sender/strategy/dispatcher references and pending/resolved
   event registrations/priorities. Negative compilation cases cover both phases.

Both full affected commands passed after these corrections:

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev check` | **612 architecture tests / 4654 assertions + 56 unit tests / 705 assertions** (**668 / 5359 total**); Deptrac **1048 allowed / 0 violations / 0 uncovered**; all checks pass | `var/test-runs/run-Pkwbmhyv/checks.log` |
| `./bin/dev test` | **125 tests / 3250 assertions**; durable **29 / 1566**, schema **38 / 342**; retry/replay races, injected transport failures, forged markers, Compose worker, outage/recreation and full regression pass | `var/test-runs/run-igpmh8TN/` |

The successful consumer setup evidence above predates these bounded review fixes;
bootstrap/dependencies/Compose remained unchanged. The full current check/test runs
reverify changed compiler/schema/runtime behavior.

### Final alias-routing follow-up and approvals

Runtime re-review found that FrameworkBundle accepts the active transport's service
ID for consumption while its retry/failure locators use the canonical queue name.
Worker failure events now normalize that service ID to `durable_events` before
standard listeners. Unit locators match production; an actual compiled PostgreSQL
worker invoked with `messenger.transport.durable_events` proves permanent failure
retention, three ordinary retries and successful downstream processing.

Latest full commands both exited **0**:

| Command | Result | Evidence |
| --- | --- | --- |
| `./bin/dev check` | **668 tests / 5367 assertions** (612 architecture / 4654 + 56 unit / 713); Deptrac **1048 allowed / 0 violations / 0 uncovered**; all checks pass | `var/test-runs/run-Jn1FBtUv/checks.log` |
| `./bin/dev test` | **126 tests / 3289 assertions**, including durable **30 / 1605**; all PostgreSQL, Compose worker, outage and recovery phases pass | `var/test-runs/run-EorAJLX5/` |

- Runtime reviewer `ses_f68f14ef3ffewLSWNamP8X1bF5`: **approved**, all original
  findings and the service-ID follow-up resolved; no remaining actionable findings.
- Boundary/tooling reviewer `ses_f68f14ea0ffeEGNiQLR1NW07wc`: **approved**, all
  three findings resolved; no new actionable findings.

The consumer verification above remains supporting bootstrap/isolation evidence;
the current full checks/E2E establish the later bounded corrections. Implementation,
verification and independent review of this historical design were complete. It
was subsequently superseded by user-accepted 5b; next is Subtask 6 discovery/design.
