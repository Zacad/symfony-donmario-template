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
canary_container=

compose() {
    docker compose --project-directory "$ROOT" --env-file "$SETTINGS" --project-name "$PROJECT_ID" \
        --file "$ROOT/compose.yaml" --file "$ROOT/compose.test.yaml" "$@"
}

redact() {
    docker run --rm -i --entrypoint php --volume "$RUN_DIR:/evidence:ro,z" "$RUNTIME_IMAGE" \
        docker/tools/redact.php /evidence/settings.env
}

# Capture a generation before its container disappears. Raw logs are checked
# before any publication, then scrubbed for exact private state and credentials.
# Exit 1 means a secrecy rejection; exit 2 means collection/redaction failed.
capture_logs() {
    log_label=$1
    shift
    log_status=0
    "$@" > "$RUN_DIR/$log_label.raw" 2>&1 || log_status=2
    if [ "$mode" = test ]; then
        if ! compose run --rm --no-deps -T runner php docker/tools/authenticating-log-check.php < "$RUN_DIR/$log_label.raw" > "$RUN_DIR/$log_label-secrecy.raw" 2>&1; then
            if [ "$log_status" = 0 ]; then log_status=1; fi
        fi
        if ! compose run --rm --no-deps -T runner php docker/tools/authenticating-log-check.php --redact < "$RUN_DIR/$log_label.raw" > "$RUN_DIR/$log_label-sanitized.raw" 2> "$RUN_DIR/$log_label-redactor.raw"; then
            log_status=2
            printf '%s\n' 'Authentication log redaction failed; raw evidence withheld.' > "$RUN_DIR/$log_label-sanitized.raw"
        fi
        redact < "$RUN_DIR/$log_label-secrecy.raw" > "$RUN_DIR/$log_label-secrecy.log" || log_status=2
        redact < "$RUN_DIR/$log_label-redactor.raw" > "$RUN_DIR/$log_label-redactor.log" || log_status=2
    else
        cp "$RUN_DIR/$log_label.raw" "$RUN_DIR/$log_label-sanitized.raw"
    fi
    if ! redact < "$RUN_DIR/$log_label-sanitized.raw" > "$RUN_DIR/$log_label.log"; then
        log_status=2
        printf '%s\n' 'Container log redaction failed; raw evidence withheld.' > "$RUN_DIR/$log_label.log"
    fi
    rm -f "$RUN_DIR/$log_label.raw" "$RUN_DIR/$log_label-sanitized.raw" "$RUN_DIR/$log_label-secrecy.raw" "$RUN_DIR/$log_label-redactor.raw"
    printf 'Container generation %s: collection/secrecy/redaction exit=%s\n' "$log_label" "$log_status"
    return "$log_status"
}

retire_generation() {
    # Stop first to include shutdown logs and prevent writes after the snapshot.
    step "$1-stop" compose --profile worker stop "$2"
    capture_logs "$1" compose --profile worker logs --no-color "$2"
}

authentication_log_contract() {
    for canary_kind in password jwt private-key; do
        canary_container=$PROJECT_ID-log-canary
        compose run --no-deps -T --name "$canary_container" runner php docker/tools/authenticating-log-canary.php "$canary_kind" > /dev/null 2>&1 || return 1
        contract_status=0
        capture_logs "log-history-injected-$canary_kind" docker logs "$canary_container" > /dev/null || contract_status=$?
        docker rm "$canary_container" > /dev/null || return 1
        canary_container=
        test "$contract_status" = 1 || return 1
    done
    capture_logs log-history-current compose logs --no-color app || return 1
    printf '%s\n' 'Verified earlier removed generation fails the actual unredacted secrecy checker while the current generation passes; retained evidence is redacted.'
}

cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    if [ "$ready" = 1 ]; then
        if [ -n "$canary_container" ]; then
            docker rm --force "$canary_container" > /dev/null 2>&1 || { if [ "$status" = 0 ]; then status=1; fi; }
        fi
        compose --profile worker stop > "$RUN_DIR/cleanup-stop.raw" 2>&1 || {
            if [ "$status" = 0 ]; then status=1; fi
        }
        redact < "$RUN_DIR/cleanup-stop.raw" > "$RUN_DIR/cleanup-stop.log" || { if [ "$status" = 0 ]; then status=1; fi; }
        rm -f "$RUN_DIR/cleanup-stop.raw"
        capture_logs containers compose --profile worker logs --no-color || { if [ "$status" = 0 ]; then status=1; fi; }
        # An interrupted step/capture can leave private temporary output. Preserve
        # only its checked/redacted evidence before removing the isolated runtime.
        for pending_log in "$RUN_DIR"/*.raw; do
            test -f "$pending_log" || continue
            capture_logs "interrupted-${pending_log##*/}" cat "$pending_log" || { if [ "$status" = 0 ]; then status=1; fi; }
            rm -f "$pending_log"
        done
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

authentication_storage() {
    compose run --rm --no-deps -T runner php docker/tools/authenticating-storage.php export |
        compose exec -T app php docker/tools/authenticating-storage.php inspect
}

jwt_keys() {
    # The only writable mount of signing material in the test journey. Unique
    # project volume and separate completed marker; app/runner mounts are read-only.
    docker volume create --label "com.donmario.checkout=$CHECKOUT_ID" --label com.donmario.environment=test \
        --label "com.docker.compose.project=$PROJECT_ID" --label com.docker.compose.volume=jwt_keys "${PROJECT_ID}_jwt_keys" >/dev/null
    docker run --rm --read-only --network none --cap-drop ALL --security-opt no-new-privileges:true \
        --entrypoint php --volume "${PROJECT_ID}_jwt_keys:/app/var/jwt" \
        --volume "$RUN_DIR:/evidence:z" "$RUNTIME_IMAGE" \
        docker/tools/jwt-keys.php "$1" /app/var/jwt /evidence/jwt-initialized
}

jwt_signing_outage() {
    docker run --rm --read-only --network none --cap-drop ALL --security-opt no-new-privileges:true \
        --entrypoint php --env APP_ENV=test --volume "${PROJECT_ID}_jwt_keys:/app/var/jwt" \
        "$RUNTIME_IMAGE" docker/tools/jwt-test-key-outage.php "$1"
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
    step jwt-key-initialization jwt_keys initialize
    step jwt-key-retention jwt_keys initialize
    step jwt-keyless-worker compose --profile worker run --rm --no-deps worker php -r 'if (is_file("/app/var/jwt/active/private.pem") || is_file("/app/var/jwt/active/public.pem")) { exit(1); } echo "Verified worker has no signing or verification material.\n";'
    step database-startup compose up --detach --wait --wait-timeout 60 database
    step migrations compose run --rm --no-deps runner php bin/console doctrine:migrations:migrate --no-interaction
    step schema compose run --rm --no-deps runner php bin/console app:architecture:check --database
    step startup compose up --detach --wait --wait-timeout 60 app
    step authenticating-log-history authentication_log_contract
    step healthy compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/HealthyTest.php
    step migration-tests compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/MigrationsTest.php
    step persistence-boundaries compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceBoundariesTest.php
    step cqrs compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/CqrsTest.php
    step collections compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/CollectionsTest.php
    step authorizing compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizingTest.php
    step authorization-enforcement compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --exclude-group authorization-outage-prepare --exclude-group authorization-outage-down --exclude-group authorization-outage-recover
    step persistence-create compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceCreateTest.php
    step authenticating-provision compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingProvisionTest.php
    step authenticating-jwt compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtTest.php
    step authenticating-jwt-input compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtInputTest.php
    step authenticating-jwt-claims compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtClaimsTest.php
    step authenticating-jwt-identity compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtIdentityTest.php
    step authenticating-jwt-migration compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtMigrationTest.php
    step authenticating-jwt-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testSeedTokenForRestartRotationAndOutages
    step authenticating-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingPersistenceTest.php --filter testSeedPersistentSessionAndLimiter
    step authenticating-storage authentication_storage
    step authenticating-jwt-throttle-seed compose exec -T -e E2E_BASE_URL=http://127.0.0.1:8080 app php vendor/bin/phpunit tests/E2E/AuthenticatingJwtThrottleTest.php --filter testSeedCombinedNormalizedWebApiFailureBudgets
    step authenticating-cache-clear compose exec -T app php bin/console cache:clear --no-interaction
    retire_generation authenticating-seeded-generation app
    step authenticating-app-recreate compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-throttle-restart compose exec -T -e E2E_BASE_URL=http://127.0.0.1:8080 app php vendor/bin/phpunit tests/E2E/AuthenticatingJwtThrottleTest.php --filter testCombinedLimiterSurvivesAppRecreationAndCacheClear
    step authenticating-restart compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingPersistenceTest.php --filter testSessionAndCountersSurviveAppRecreationAndCacheClear
    step authenticating-jwt-restart compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testTokenSurvivesAppRecreationAndCacheClear
    # Loopback has an independent IP budget. One bounded real wait covers both
    # native sliding-window expiry and the earlier runner restart-test counter.
    step authenticating-throttling compose exec -T -e E2E_BASE_URL=http://localhost:8080 app php vendor/bin/phpunit tests/E2E/AuthenticatingThrottleTest.php
    step authenticating-jwt-throttle-recovery compose exec -T -e E2E_BASE_URL=http://127.0.0.1:8080 app php vendor/bin/phpunit tests/E2E/AuthenticatingJwtThrottleTest.php --filter testCombinedLimiterRecoversAfterExistingRealExpiryPhase
    step authenticating-web compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingWebTest.php
    retire_generation authenticating-jwt-original-generation app
    step authenticating-jwt-rotate jwt_keys rotate
    step authenticating-jwt-rotated-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-overlap compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testRotationOverlapAcceptsOldAndNewTokens
    retire_generation authenticating-jwt-overlap-generation app
    step authenticating-jwt-retire jwt_keys retire
    step authenticating-jwt-retired-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-retirement compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testRetiringOldKeyRejectsOldTokenButKeepsNewToken
    retire_generation authenticating-jwt-before-emergency app
    step authenticating-jwt-emergency-rotate jwt_keys rotate-emergency
    step authenticating-jwt-emergency-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-emergency-replay compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testEmergencyRotationRejectsUnexpiredReplayAndAllowsNewIssuance
    retire_generation authenticating-jwt-before-signing-outage app
    step authenticating-jwt-remove-signing-key jwt_signing_outage hide
    step authenticating-jwt-signing-outage-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-signing-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtSigningDownTest.php
    retire_generation authenticating-jwt-signing-outage-generation app
    step authenticating-jwt-restore-signing-key jwt_signing_outage restore
    step authenticating-jwt-signing-recovered-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step authenticating-jwt-signing-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testDatabaseAndSigningRecoveryKeepIdentityAndAllowNewIssuance
    step events-sync compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-fixture --group native-mode
    # Keep app/runner var volumes: a warmed container must resolve the DSN anew.
    EVENT_TRANSPORT_DSN='doctrine://default'
    export EVENT_TRANSPORT_DSN
    retire_generation authenticating-web-generation app
    step events-async-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step events-async compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-fixture --group native-mode --group native-async
    step events-worker-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testSeedComposeWorker
    step events-worker-start compose --profile worker up --detach --no-deps --wait --wait-timeout 60 worker
    step events-worker-observe compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testObserveComposeWorker
    step events-worker-status compose --profile worker ps --status running worker
    test -n "$(compose --profile worker ps --status running --quiet worker)"
    retire_generation events-worker-generation worker
    test -z "$(compose --profile worker ps --status running --quiet worker)"
    step events-worker-remove compose --profile worker rm --force worker
    step events-outage-seed compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testSeedOutage
    step authorization-enforcement-prepare compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testPrepareOutage
    step stop-database compose stop database
    step database-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/DatabaseDownTest.php
    step authorizing-database-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizingDatabaseDownTest.php
    step authorization-enforcement-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testAssertOutage
    step authenticating-database-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingDatabaseDownTest.php
    step authenticating-jwt-database-down compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtDatabaseDownTest.php
    old_database=$(compose ps --all --quiet database)
    capture_logs database-outage-generation compose logs --no-color database
    step recreate-database compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 database
    new_database=$(compose ps --quiet database)
    test "$old_database" != "$new_database"
    step migration-repeat compose run --rm --no-deps runner php bin/console doctrine:migrations:migrate --no-interaction
    step recovery compose up --detach --wait --wait-timeout 60 app
    step persistence-read compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/PersistenceReadTest.php
    step authorizing-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizingTest.php --filter testRecoveryChecks
    step authorization-enforcement-recover compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthorizationEnforcementTest.php --filter testRecoverOutage
    step authenticating-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingPersistenceTest.php --filter testExpiredLimiterAndDatabaseRecoveryKeepTheSameAccount
    step authenticating-jwt-database-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/AuthenticatingJwtPersistenceTest.php --filter testDatabaseAndSigningRecoveryKeepIdentityAndAllowNewIssuance
    step events-outage-recovery compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --filter testRecoverOutage
    EVENT_TRANSPORT_DSN='sync://'
    export EVENT_TRANSPORT_DSN
    retire_generation events-async-generation app
    step events-sync-return-app compose up --detach --no-deps --force-recreate --wait --wait-timeout 60 app
    step events-sync-return compose run --rm --no-deps runner php vendor/bin/phpunit tests/E2E/EventsTest.php --group native-mode
fi
