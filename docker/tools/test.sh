#!/bin/sh
set -eu
umask 077

mode=$1
mkdir -p "$ROOT/var/test-runs"
RUN_DIR=$(mktemp -d "$ROOT/var/test-runs/run-XXXXXXXX")
run_name=${RUN_DIR##*/}
PROJECT_ID="$CHECKOUT_ID-test-$(printf '%s' "$run_name" | tr '[:upper:]' '[:lower:]')"
RUNTIME_IMAGE=$PROJECT_ID:local
DATABASE_IMAGE=$PROJECT_ID-database:local
LOCAL_UID=$(id -u)
LOCAL_GID=$(id -g)
APP_MODE=test
APP_DATABASE_NAME=app_test
APP_PORT=8080
export PROJECT_ID RUNTIME_IMAGE DATABASE_IMAGE LOCAL_UID LOCAL_GID APP_MODE APP_DATABASE_NAME APP_PORT
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES COMPOSE_ENV_FILES DATABASE_URL APP_ENV APP_DEBUG APP_SECRET APP_DATABASE_PASSWORD POSTGRES_PASSWORD EVENT_TRANSPORT_DSN
EVENT_TRANSPORT_DSN='sync://'
export EVENT_TRANSPORT_DSN
SETTINGS=$RUN_DIR/settings.env
ready=0
app_image_built=0
database_image_built=0

compose() {
    docker compose --project-directory "$ROOT" --env-file "$SETTINGS" --project-name "$PROJECT_ID" \
        --file "$ROOT/compose.yaml" --file "$ROOT/compose.test.yaml" "$@"
}

redact() {
    docker run --rm -i --entrypoint php --volume "$RUN_DIR:/evidence:ro,z" "$RUNTIME_IMAGE" \
        docker/tools/redact.php /evidence/settings.env
}

cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    if [ "$ready" = 1 ]; then
        compose --profile worker logs --no-color > "$RUN_DIR/containers.raw" 2>&1 || {
            printf '%s\n' 'Container log collection failed.' >&2
            if [ "$status" = 0 ]; then status=1; fi
        }
        if redact < "$RUN_DIR/containers.raw" > "$RUN_DIR/containers.log"; then
            rm -f "$RUN_DIR/containers.raw"
        elif [ "$status" = 0 ]; then status=1; fi
        compose --profile worker down --volumes --remove-orphans || { if [ "$status" = 0 ]; then status=1; fi; }
    fi
    # This run's unique image tag only; shared tooling/development images are preserved.
    : > "$RUN_DIR/image-cleanup.log"
    for built_image in "$app_image_built:$RUNTIME_IMAGE" "$database_image_built:$DATABASE_IMAGE"; do
        if [ "${built_image%%:*}" = 1 ] && ! docker image rm "${built_image#*:}" >> "$RUN_DIR/image-cleanup.log" 2>&1; then
            printf '%s\n' 'Unique test image cleanup failed; see image-cleanup.log.' >&2
            if [ "$status" = 0 ]; then status=1; fi
        fi
    done
    if [ "$status" = 0 ]; then
        rm -f "$SETTINGS"
        printf 'PASS: %s. Evidence: %s\n' "$mode" "$RUN_DIR"
    else
        printf 'FAILED: %s (exit %s). Private diagnostics: %s\n' "$mode" "$status" "$RUN_DIR" >&2
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

step() {
    name=$1
    shift
    printf 'Running %s...\n' "$name"
    started=$(date +%s)
    result=0
    "$@" > "$RUN_DIR/$name.raw" 2>&1 || result=$?
    redact < "$RUN_DIR/$name.raw" > "$RUN_DIR/$name.log"
    rm -f "$RUN_DIR/$name.raw"
    printf '\nelapsed_seconds=%s exit_status=%s\n' "$(($(date +%s) - started))" "$result" >> "$RUN_DIR/$name.log"
    cat "$RUN_DIR/$name.log"
    return "$result"
}

docker build --builder default --target test --build-arg "APP_UID=$LOCAL_UID" --build-arg "APP_GID=$LOCAL_GID" \
    --tag "$RUNTIME_IMAGE" "$ROOT"
app_image_built=1
docker build --builder default --tag "$DATABASE_IMAGE" "$ROOT/docker/postgres"
database_image_built=1
docker run --rm --volume "$RUN_DIR:/evidence:z" "$RUNTIME_IMAGE" \
    php docker/tools/settings.php /evidence/settings.env "$PROJECT_ID" "$LOCAL_UID" "$LOCAL_GID" test
# No inherited dev settings: Compose loads ONLY this generated file.
ready=1
compose config --format json | docker run --rm -i --entrypoint php "$RUNTIME_IMAGE" docker/tools/verify-test-config.php
compose --profile worker config --format json | docker run --rm -i --entrypoint php "$RUNTIME_IMAGE" docker/tools/verify-test-config.php

if [ "$mode" = check ]; then
    step checks compose run --rm --no-deps runner sh docker/tools/check.sh
    step native-fixture-offline compose run --rm --no-deps runner php tests/Fixtures/NativeEvents/inspect.php
else
    step database-startup compose up --detach --wait --wait-timeout 60 database
    step migrations compose run --rm --no-deps runner php bin/console doctrine:migrations:migrate --no-interaction
    step schema compose run --rm --no-deps runner php bin/console app:architecture:check --database
    step startup compose up --detach --wait --wait-timeout 60 app
    step healthy compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/HealthyTest.php
    step migration-tests compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/MigrationsTest.php
    step persistence-boundaries compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceBoundariesTest.php
    step cqrs compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/CqrsTest.php
    step persistence-create compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceCreateTest.php
    step events-sync compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-fixture --group native-mode
    # Keep app/runner var volumes: a warmed container must resolve the DSN anew.
    EVENT_TRANSPORT_DSN='doctrine://default'
    export EVENT_TRANSPORT_DSN
    step events-async-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step events-async compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-fixture --group native-mode --group native-async
    step events-worker-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testSeedComposeWorker
    step events-worker-start compose --profile worker up --detach --no-deps --wait --wait-timeout 60 worker
    step events-worker-observe compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testObserveComposeWorker
    step events-worker-status compose --profile worker ps --status running worker
    test -n "$(compose --profile worker ps --status running --quiet worker)"
    step events-worker-stop compose --profile worker stop worker
    step events-worker-logs compose --profile worker logs --no-color worker
    test -z "$(compose --profile worker ps --status running --quiet worker)"
    step events-worker-remove compose --profile worker rm --force worker
    step events-outage-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testSeedOutage
    step stop-database compose stop database
    step database-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/DatabaseDownTest.php
    old_database=$(compose ps --all --quiet database)
    step recreate-database compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 database
    new_database=$(compose ps --quiet database)
    test "$old_database" != "$new_database"
    step migration-repeat compose run --rm --no-deps runner php bin/console doctrine:migrations:migrate --no-interaction
    step recovery compose up --detach --wait --wait-timeout 60 app
    step persistence-read compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceReadTest.php
    step events-outage-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testRecoverOutage
    EVENT_TRANSPORT_DSN='sync://'
    export EVENT_TRANSPORT_DSN
    step events-sync-return-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step events-sync-return compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-mode
fi
