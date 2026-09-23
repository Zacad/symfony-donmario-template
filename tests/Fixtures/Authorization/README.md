# Authorization enforcement E2E orchestration

`tests/E2E/AuthorizationEnforcementTest.php` uses the existing `AuthorizingKernel`,
bounded `SqlLog` and `FaultControl`. It loads the production voters and buses.
Disposable PostgreSQL fixture inserts establish accounts, tasks and global grants;
real operator console adapters perform HTTP-journey grant/revoke operations. Direct
account calls use native `UsernamePasswordToken` plus a `RequestStack` scope restored
in `finally`. No voter is replaced and no blanket admission exemption is added.

`docker/tools/test.sh` coordinates these four invocations using its existing `step`
and isolated `compose` helpers:

```sh
# Healthy app + migrated PostgreSQL; preferably alongside authorizing/CQRS,
# before the deliberate authentication limiter-failure phases.
step authorization-enforcement compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --exclude-group authorization-outage-prepare --exclude-group authorization-outage-down --exclude-group authorization-outage-recover

# Before the existing stop-database step. Retain the same runner /app/var volume.
step authorization-enforcement-prepare compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testPrepareOutage

# After stop-database, with the HTTP app still running.
step authorization-enforcement-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testAssertOutage

# After recreate-database, migration-repeat and application recovery.
step authorization-enforcement-recover compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testRecoverOutage
```

Do not run the entire class unfiltered against a healthy database: its outage
assertion intentionally requires PostgreSQL to be stopped. The three phase methods
are also tagged `authorization-outage-prepare`, `authorization-outage-down` and
`authorization-outage-recover`, respectively. No extra container, migration, route,
worker, restart or sleep is required.

Prepare retains two UUIDs and a generated title prefix in the owner-only
`/app/var/authorization-enforcement-state.json` marker, plus one account, one task
and two permission grants in the disposable database. It stores no password, hash,
token or cookie. The down phase boots without a setup connection, uses a native
account token to reach real voter support reads, asserts bounded database failure
without task handler calls, checks scope cleanup, and exercises anonymous HTTP
denial while the database is unavailable. A command can fail opening its transaction;
the protected Get independently proves unavailable support reads cannot allow admission.
Recovery checks retained data, absence of the attempted outage write, successful
protected calls, native browser login and live HTTP revocation; it removes the marker
and its owned rows. Ordinary cases clean only their own generated accounts/tasks.

The successful direct Get budget is **exactly three business reads per independent
call**: one account-existence read, one permission evaluation and one task read, with
zero writes. This deliberately excludes the native HTTP credential-provider lookup.
The nested denial cases establish both an ORM-scheduled task and an immediate SQL
assignment visible only inside the root, swallow a nested command/query denial,
attempt further otherwise-authorized work, and verify rollback plus same-process
recovery. The command variant revokes the actor's create permission inside the root
before the denied nested create, and verifies that revocation itself rolls back.

Local non-E2E verification commands:

```sh
./bin/dev composer exec -- php -l tests/E2E/AuthorizationEnforcementTest.php
./bin/dev composer exec -- php vendor/bin/phpstan analyse --no-progress --memory-limit=512M tests/E2E/AuthorizationEnforcementTest.php
./bin/dev composer exec -- php vendor/bin/php-cs-fixer fix --dry-run --diff --sequential tests/E2E/AuthorizationEnforcementTest.php
# These unit tests use mocks only and require no database/bootstrap.
./bin/dev composer exec -- php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Unit/AuthorizationVotersTest.php
```

The explicit autoload-only invocation runs only the mock-based unit file. Current
coordinated verification evidence is recorded in the
[active authorization rework task](../../../docs/tasks/10-authorizing-rework.md).
