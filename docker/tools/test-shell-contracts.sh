#!/bin/sh
set -eu

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
        for arg do
            case "$arg" in
                version) printf '%s\n' 5.5.0; exit 0 ;;
                logs) if [ "${STUB_FAULT:-}" = logs ]; then exit 43; fi ;;
                config) printf '%s\n' '{}'; exit 0 ;;
                doctrine:migrations:migrate) if [ "${STUB_FAULT:-}" = migrations ]; then exit 44; fi ;;
            esac
        done ;;
    run)
        for arg do
            case "$arg" in
                docker/tools/redact.php) cat; exit 0 ;;
                docker/tools/verify-test-config.php) cat >/dev/null; exit 0 ;;
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
! grep -q 'Application ready:' "$work/setup-migration.log"
! grep -q 'up --detach --wait --wait-timeout 60 app' "$STUB_LOG"
: > "$STUB_LOG"
result=0
PATH="$work/bin:$PATH" STUB_FAULT=migrations ROOT=/app CHECKOUT_ID=dm-contract DEV_IMAGE=unused \
    sh docker/tools/test.sh test > "$work/test-migration.log" 2>&1 || result=$?
test "$result" = 44
! grep -q 'PASS:' "$work/test-migration.log"
! grep -q 'up --detach --wait --wait-timeout 60 app' "$STUB_LOG"
grep -q 'down --volumes --remove-orphans' "$STUB_LOG"

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
printf '%s\n' 'Shell contracts passed: endpoint precedence, migration failures, settings refusal, cleanup failures, and credential argv isolation.'
