#!/bin/sh
set -eu
export EVENT_TRANSPORT_DSN=sync://

# Focused fault injection. No real Docker daemon or PostgreSQL process is used here;
# the real installation/database/HTTP journeys are covered by the E2E commands.
work=$(mktemp -d /app/var/shell-contracts-XXXXXXXX)
trap 'rm -rf "$work"' EXIT
mkdir "$work/bin"
export STUB_LOG=$work/docker.calls
cat > "$work/bin/docker" <<'STUB'
#!/bin/sh
set -eu
printf '%s\n' "$*" >> "$STUB_LOG"
case "$1" in
    context)
        case " $* " in *' remote '*) printf '%s\n' ssh://invalid.example ;;
            *) printf '%s\n' unix:///validated.sock ;; esac ;;
    volume) exit 0 ;;
    ps) if [ "${STUB_FAULT:-}" = jwt-running ]; then printf '%s\n' running-app; fi ;;
    build)
        if [ "${STUB_MODE:-}" = endpoint ]; then
            test "$DOCKER_HOST" = unix:///validated.sock
            test -z "${DOCKER_CONTEXT:-}"
            printf '%s\n' 'validated-build' >> "$STUB_LOG"
            exit 77
        fi ;;
    image)
        if [ "${STUB_FAULT:-}" = image ]; then exit 42; fi ;;
    compose)
        case " $* " in
            *' app:authorization:role:define task_tracking.user '*)
                if [ "${STUB_FAULT:-}" = authorization-task-role ]; then exit 46; fi ;;
            *' app:authorization:role:define authorizing.administrator '*)
                if [ "${STUB_FAULT:-}" = authorization-authorizing-role ]; then exit 47; fi ;;
            *' app:authorization:role:define application.administrator '*)
                if [ "${STUB_FAULT:-}" = authorization-application-role ]; then exit 48; fi ;;
        esac
        for arg do
            case "$arg" in
                version) printf '%s\n' 5.5.0; exit 0 ;;
                logs) if [ "${STUB_FAULT:-}" = logs ]; then exit 43; fi ;;
                config) printf '%s\n' '{}'; exit 0 ;;
                doctrine:migrations:migrate) if [ "${STUB_FAULT:-}" = migrations ]; then exit 44; fi ;;
            esac
        done
        if [ "${STUB_MODE:-}" = events ]; then
            printf 'event-mode=%s\n' "${EVENT_TRANSPORT_DSN-unset}" >> "$STUB_LOG"
        fi ;;
    run)
        for arg do
            case "$arg" in
                docker/tools/redact.php) cat; exit 0 ;;
                docker/tools/verify-test-config.php) cat >/dev/null; exit 0 ;;
                /tools/jwt-keys.php)
                    if [ "${STUB_FAULT:-}" = jwt ]; then exit 45; fi ;;
            esac
        done ;;
esac
STUB
chmod +x "$work/bin/docker"

# Docker gives DOCKER_CONTEXT precedence, then DOCKER_HOST, then its selected context.
for pair in remote:unix:///ignored.sock local:ssh://ignored.example; do
    : > "$STUB_LOG"
    result=0
    PATH="$work/bin:$PATH" STUB_MODE=endpoint DOCKER_CONTEXT=${pair%%:*} DOCKER_HOST=${pair#*:} \
        sh bin/dev setup > "$work/endpoint.log" 2>&1 || result=$?
    case "$pair" in
        remote:*) test "$result" -ne 0; ! grep -q '^build\|^volume\|^validated-build' "$STUB_LOG" ;;
        local:*) test "$result" = 77; grep -q '^validated-build$' "$STUB_LOG" ;;
    esac
done

# A failed migration stops both setup and E2E before application startup/success.
checkout=$work/application
mkdir -p "$checkout/bin" "$checkout/var/docker"
cp bin/dev "$checkout/bin/dev"
php docker/tools/settings.php "$checkout/var/docker/local.env" dm-migration-contract "$(id -u)" "$(id -g)" dev 0
: > "$STUB_LOG"
result=0
PATH="$work/bin:$PATH" STUB_FAULT=migrations sh "$checkout/bin/dev" setup > "$work/setup-migration.log" 2>&1 || result=$?
test "$result" = 44
! grep -q 'Application ready with semantic authorization defaults:' "$work/setup-migration.log"
! grep -q 'up --detach --wait --wait-timeout 60 app' "$STUB_LOG"
: > "$STUB_LOG"
result=0
PATH="$work/bin:$PATH" STUB_FAULT=migrations ROOT=/app CHECKOUT_ID=dm-contract DEV_IMAGE=unused \
    sh docker/tools/test.sh test > "$work/test-migration.log" 2>&1 || result=$?
test "$result" = 44
! grep -q 'PASS:' "$work/test-migration.log"
! grep -q 'up --detach --wait --wait-timeout 60 app' "$STUB_LOG"
grep -q 'down --volumes --remove-orphans' "$STUB_LOG"

# Incompatible customized defaults fail visibly through safe create-if-absent
# commands, before app startup, without issuing a revisioned overwrite.
for fixture in authorization-task-role authorization-authorizing-role authorization-application-role; do
    : > "$STUB_LOG"
    result=0
    PATH="$work/bin:$PATH" STUB_FAULT=$fixture sh "$checkout/bin/dev" setup > "$work/$fixture.log" 2>&1 || result=$?
    test "$result" = 1
    ! grep -q 'up --detach --wait --wait-timeout 60 app' "$STUB_LOG"
    ! grep -q -- '--expected-revision' "$STUB_LOG"
    case "$fixture" in
        authorization-task-role)
            grep -q 'role:define task_tracking.user Task user task_tracking.task.create task_tracking.task.view task_tracking.task.complete --if-absent' "$STUB_LOG"
            grep -q '^Failed to seed task user role; an existing default may be incompatible or retired\.$' "$work/$fixture.log"
            ! grep -q 'role:define authorizing.administrator' "$STUB_LOG" ;;
        authorization-authorizing-role)
            grep -q 'role:define authorizing.administrator Authorization administrator authorizing.manage authorizing.catalogue.manage --if-absent' "$STUB_LOG"
            grep -q '^Failed to seed authorization administrator role; an existing default may be incompatible or retired\.$' "$work/$fixture.log"
            ! grep -q 'role:define application.administrator' "$STUB_LOG" ;;
        authorization-application-role)
            grep -q 'role:define application.administrator Application administrator authorizing.manage authorizing.catalogue.manage task_tracking.task.create task_tracking.task.view task_tracking.task.complete --if-absent' "$STUB_LOG"
            grep -q '^Failed to seed application administrator role; an existing default may be incompatible or retired\.$' "$work/$fixture.log" ;;
    esac
done

# Successful setup seeds only the three clean role snapshots and never rewrites
# revisions or invokes a removed authorization surface.
: > "$STUB_LOG"
PATH="$work/bin:$PATH" sh "$checkout/bin/dev" setup > "$work/setup-defaults.log" 2>&1
grep -q '^Application ready with semantic authorization defaults: http://' "$work/setup-defaults.log"
test "$(grep -c ' app:authorization:role:define ' "$STUB_LOG")" = 3
grep -q 'role:define task_tracking.user Task user task_tracking.task.create task_tracking.task.view task_tracking.task.complete --if-absent' "$STUB_LOG"
grep -q 'role:define authorizing.administrator Authorization administrator authorizing.manage authorizing.catalogue.manage --if-absent' "$STUB_LOG"
grep -q 'role:define application.administrator Application administrator authorizing.manage authorizing.catalogue.manage task_tracking.task.create task_tracking.task.view task_tracking.task.complete --if-absent' "$STUB_LOG"
if grep -q 'task_tracking\.reader\|task_tracking\.editor\|task_tracking\.creator\|initial-role:configure\|--global\|--resource-' "$STUB_LOG"; then exit 1; fi

# Setup and disposable-consumer tooling must not retain removed resource,
# initial-binding, scoped-role or account-column compatibility contracts.
for script in bin/dev docker/tools/consumer-authorizing.php docker/tools/consumer-task-tracking.php docker/tools/verify-setup.sh; do
    if grep -Eq 'authorizing_(role_definition|global_|resource_|initial_resource_role)|account_id|resourceType|resourceId|resource_type|resource_id|--resource-|--global|initial-role:configure|task_tracking\.(creator|reader|editor)|Version202609(17010000|20020000)' "$script"; then
        printf 'Removed authorization surface remains in %s.\n' "$script" >&2
        exit 1
    fi
done
if grep -q "['\"]scope['\"]" docker/tools/consumer-authorizing.php; then
    printf '%s\n' 'Removed assignment scope field remains in consumer authorization tooling.' >&2
    exit 1
fi

# Runtime mode survives credential loading and reaches every development adapter.
settings_checksum=$(cksum < "$checkout/var/docker/local.env")
for selection in sync:// doctrine://default; do
    for operation in setup up console composer; do
        : > "$STUB_LOG"
        PATH="$work/bin:$PATH" STUB_MODE=events EVENT_TRANSPORT_DSN=$selection \
            sh "$checkout/bin/dev" "$operation" > "$work/event-mode.log" 2>&1
        grep -q "^event-mode=$selection$" "$STUB_LOG"
        if grep -q 'up .* worker$' "$STUB_LOG"; then exit 1; fi
    done
done
test "$settings_checksum" = "$(cksum < "$checkout/var/docker/local.env")"
# Key loss/invalid state blocks startup; rotation requires explicit stopped apps.
: > "$STUB_LOG"
result=0
PATH="$work/bin:$PATH" STUB_FAULT=jwt sh "$checkout/bin/dev" up > "$work/jwt.log" 2>&1 || result=$?
test "$result" = 45
if grep -q 'up --detach' "$STUB_LOG"; then exit 1; fi
for operation in rotate rotate-emergency retire; do
    : > "$STUB_LOG"
    if PATH="$work/bin:$PATH" STUB_FAULT=jwt-running sh "$checkout/bin/dev" jwt-keys "$operation" > "$work/jwt.log" 2>&1; then exit 1; fi
    if grep -q '/tools/jwt-keys.php' "$STUB_LOG"; then exit 1; fi
    : > "$STUB_LOG"
    PATH="$work/bin:$PATH" sh "$checkout/bin/dev" jwt-keys "$operation" > "$work/jwt.log" 2>&1
    grep -q "/tools/jwt-keys.php $operation " "$STUB_LOG"
    if grep -q 'up --detach' "$STUB_LOG"; then exit 1; fi
done
# An unset mode defaults to sync; stop/status remain usable without the async export.
for operation in up 'worker stop' 'worker status'; do
    : > "$STUB_LOG"
    (unset EVENT_TRANSPORT_DSN; PATH="$work/bin:$PATH" STUB_MODE=events sh "$checkout/bin/dev" $operation) \
        > "$work/event-default.log" 2>&1
    grep -q '^event-mode=sync://$' "$STUB_LOG"
done
for selection in '' invalid 'doctrine://other' 'doctrine://default?queue_name=override'; do
    : > "$STUB_LOG"
    if PATH="$work/bin:$PATH" EVENT_TRANSPORT_DSN=$selection sh "$checkout/bin/dev" up > "$work/event-invalid.log" 2>&1; then
        printf '%s\n' 'Invalid event mode accepted.' >&2; exit 1
    fi
    grep -q '^EVENT_TRANSPORT_DSN must be sync:// or doctrine://default\.$' "$work/event-invalid.log"
    if grep -q 'up --detach' "$STUB_LOG"; then exit 1; fi
done
: > "$STUB_LOG"
if (unset EVENT_TRANSPORT_DSN; PATH="$work/bin:$PATH" sh "$checkout/bin/dev" worker start) > "$work/event-worker.log" 2>&1; then
    printf '%s\n' 'Synchronous worker start accepted.' >&2; exit 1
fi
grep -q '^Worker start requires async events\.' "$work/event-worker.log"
if grep -q 'up .* worker$' "$STUB_LOG"; then exit 1; fi
PATH="$work/bin:$PATH" STUB_MODE=events EVENT_TRANSPORT_DSN=doctrine://default \
    sh "$checkout/bin/dev" worker start > "$work/event-worker.log" 2>&1
grep -q '^event-mode=doctrine://default$' "$STUB_LOG"
grep -q -- '--profile worker up --detach --wait --wait-timeout 60 worker$' "$STUB_LOG"

# DSNs cannot be smuggled into the credential file, even when valid at runtime.
cp "$checkout/var/docker/local.env" "$work/event-settings.baseline"
printf '%s\n' 'EVENT_TRANSPORT_DSN=doctrine://default' >> "$checkout/var/docker/local.env"
if PATH="$work/bin:$PATH" sh "$checkout/bin/dev" up > "$work/event-settings.log" 2>&1; then exit 1; fi
grep -q '^Invalid settings key\.' "$work/event-settings.log"
cp "$work/event-settings.baseline" "$checkout/var/docker/local.env"

# The real test/check entrypoints reset caller mode before test Compose invocation.
mkdir -p "$checkout/docker/tools"
cp docker/tools/test.sh "$checkout/docker/tools/test.sh"
for operation in test check; do
    : > "$STUB_LOG"
    result=0
    PATH="$work/bin:$PATH" STUB_MODE=events STUB_FAULT=migrations EVENT_TRANSPORT_DSN=doctrine://default \
        sh "$checkout/bin/dev" "$operation" > "$work/event-tests.log" 2>&1 || result=$?
    case "$operation" in test) test "$result" = 44 ;; check) test "$result" = 0 ;; esac
    grep -q '^event-mode=sync://$' "$STUB_LOG"
    if grep -q '^event-mode=doctrine://default$' "$STUB_LOG"; then exit 1; fi
done

# A failed setup that writes replacement settings must fail verification.
printf '%s\n' 'Existing development volumes found without credentials.' > "$work/refused.log"
sh docker/tools/assert-settings-refusal.sh missing "$work/settings" "$work/unused" "$work/refused.log" 1
printf '%s\n' 'regression: replacement credential' > "$work/settings"
if sh docker/tools/assert-settings-refusal.sh missing "$work/settings" "$work/unused" "$work/refused.log" 1; then
    printf '%s\n' 'Regression guard accepted replacement settings.' >&2; exit 1
fi
printf '%s\n' 'Incomplete settings: restore original.' > "$work/refused.log"
cp "$work/settings" "$work/baseline"
sh docker/tools/assert-settings-refusal.sh incomplete "$work/settings" "$work/baseline" "$work/refused.log" 1
printf '%s\n' 'regression: changed settings' > "$work/settings"
if sh docker/tools/assert-settings-refusal.sh incomplete "$work/settings" "$work/baseline" "$work/refused.log" 1; then exit 1; fi

# Diagnostic/unique-image cleanup failures must propagate, never print PASS.
for fault in image logs; do
    : > "$STUB_LOG"
    result=0
    PATH="$work/bin:$PATH" STUB_FAULT=$fault ROOT=/app CHECKOUT_ID=dm-contract DEV_IMAGE=unused \
        sh docker/tools/test.sh check > "$work/cleanup.log" 2>&1 || result=$?
    test "$result" -ne 0
    ! grep -q 'PASS:' "$work/cleanup.log"
    grep -q 'down --volumes --remove-orphans' "$STUB_LOG"
    ! grep -q 'image rm dm-contract-dev' "$STUB_LOG"
done

# psql receives the password through its environment/input, never argv or output.
cat > "$work/bin/psql" <<'STUB'
#!/bin/sh
set -eu
for arg do
    case "$arg" in *"$APP_DATABASE_PASSWORD"*) exit 1 ;; esac
done
input=$(cat)
case "$input" in *'\getenv app_password APP_DATABASE_PASSWORD'*) ;; *) exit 1 ;; esac
case "$input" in *"$APP_DATABASE_PASSWORD"*) exit 1 ;; esac
STUB
chmod +x "$work/bin/psql"
PATH="$work/bin:$PATH" POSTGRES_USER=postgres APP_DATABASE_NAME=app_test APP_DATABASE_PASSWORD=synthetic-sentinel \
    sh docker/postgres/10-application.sh > "$work/initialization.log" 2>&1
! grep -q synthetic-sentinel "$work/initialization.log"
sh docker/tools/jwt-keys-contract.sh
printf '%s\n' 'Shell contracts passed: endpoint precedence, migration failure, exact authorization defaults and mismatch failures, removed authorization surfaces, settings refusal, cleanup failures, credential argv isolation, event mode propagation, worker refusal and stopped-only JWT rotation.'
