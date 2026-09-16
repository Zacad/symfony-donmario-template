# Native event verification fixture

One disposable `NativeObserving` module runs against the production kernel,
compiler passes, EventBus, Doctrine connection and native Messenger transports.
It is physically generated in a private temporary directory and autoloaded only
inside subprocesses. No production runtime service is replaced.

Every disposable command/query handler declares a co-located exact-message policy.
Those policies explicitly admit only the fixture's publish, observation and query
probes, including identity-free async delivery. They let the existing failure probes
reach their intended event/transaction guards. They grant no production authority.

`PublishCommand` stages a producer ORM row and dispatches one `PublishedEvent`.
Two ordinary private listeners call `ObserveCommand`; its module-owned
`(event UUID, effect label)` key is enforced by the database primary key and
`INSERT ... ON CONFLICT DO NOTHING`. The payload includes a UUID, microsecond
date, a concrete nested public event, Unicode text, integer, boolean and null.

Fault controls live in the disposable persistence adapter and bootstrap:

- `listener-failure`: second command throws after actual SQL;
- `lifecycle`: postFlush catches forbidden EventBus dispatch;
- `precommit`, `aftercommit`, `before-ack`: wait on a PostgreSQL advisory lock
  held by the independent test connection. `pg_locks` establishes arrival;
  sleeps are bounded polling, not evidence of commit or visibility;
- test-owned PostgreSQL CHECK constraints cause real enqueue/final-flush errors.

`held.php` retains the actual buses, repository and lazy manager across caught
query/event/lifecycle failures and database flush errors, then runs healthy work.
The async retry test adds a current third listener after enqueue and recompiles.
Native HandledStamps, native retries/failure transport and native operator retry
remain active. SIGKILL tests kill real producer/worker subprocesses; lease ageing
uses SQL to avoid a five-minute wait before repeat delivery.

## Commands and phases

Offline fixture/source/container/router/Deptrac verification:

```sh
./bin/dev composer exec -- php tests/Fixtures/NativeEvents/inspect.php
```

`./bin/dev check` includes that inspection. `./bin/dev test` coordinates:

1. `EventsTest.php --group native-fixture --group native-mode` with explicit `sync://`;
2. `EventsTest.php --group native-fixture --group native-mode --group native-async`
   with `doctrine://default`, recreating app and retaining caches;
3. `testSeedComposeWorker` → real Compose worker → `testObserveComposeWorker`;
4. `testSeedOutage` → PostgreSQL stop/recreation → `testRecoverOutage`;
5. return to sync and rerun `native-mode` with the warmed app/runner caches.

Every database test first asserts `app_test` and the application role. The fixture
migration is removed after each test. Phase manifests live only in the test
runner volume. Worker/outage phases use a real production `TaskCreatedEvent` and
preserve an opaque `durable_events` sentinel until their explicit cleanup.
Native fixture rows are restricted to `events` / `events_failed` on the isolated
test database. Full PostgreSQL evidence and independent review are coordinated
by the parent task; an offline pass alone is not acceptance.
