#!/bin/sh
set -eu
umask 077

# The clean-consumer journey has a fixed baseline, independent of operator mode.
export EVENT_TRANSPORT_DSN=sync://

# Exercise a consumer checkout, including first-use scripts, not just an image build.
WORK=$(mktemp -d "${TMPDIR:-/tmp}/donmario-setup-XXXXXXXX")
CHECKOUT=$WORK/application
mkdir "$CHECKOUT"
project=
image=
images_built=0
cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    if [ -f "$WORK/settings.backup" ]; then
        mv "$WORK/settings.backup" "$CHECKOUT/var/docker/local.env" || { if [ "$status" = 0 ]; then status=1; fi; }
    fi
    if [ -n "$project" ]; then
        docker compose --project-directory "$CHECKOUT" --env-file "$CHECKOUT/var/docker/local.env" \
            --project-name "$project" --file "$CHECKOUT/compose.yaml" logs --no-color > "$WORK/containers.raw" 2>&1 \
            || { if [ "$status" = 0 ]; then status=1; fi; }
        if [ -f "$CHECKOUT/var/docker/local.env" ] && docker run --rm -i --entrypoint php \
            --volume "$CHECKOUT:/workspace:ro,z" "$image" /workspace/docker/tools/redact.php /workspace/var/docker/local.env \
            < "$WORK/containers.raw" > "$WORK/containers.log"; then
            rm -f "$WORK/containers.raw"
        elif [ "$status" = 0 ]; then status=1; fi
        docker compose --project-directory "$CHECKOUT" --env-file "$CHECKOUT/var/docker/local.env" \
            --project-name "$project" --file "$CHECKOUT/compose.yaml" --profile worker down --volumes --remove-orphans \
            || { if [ "$status" = 0 ]; then status=1; fi; }
        for candidate in "$image" "$project-database:local"; do
            if [ "$images_built" = 1 ] || docker image inspect "$candidate" >/dev/null 2>&1; then
                if ! docker image rm "$candidate" >> "$WORK/image-cleanup.log" 2>&1; then
                    printf '%s\n' 'Setup verification image cleanup failed.' >&2
                    if [ "$status" = 0 ]; then status=1; fi
                fi
            fi
        done
    fi
    printf 'Setup verification exit=%s; private evidence retained at %s\n' "$status" "$WORK"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# Include intended untracked files and honor tracked working-tree deletions without
# changing the index. NUL-delimited coreutils preserve spaces/newlines in paths.
git -C "$ROOT" ls-files -z --cached --others --exclude-standard > "$WORK/candidates"
git -C "$ROOT" ls-files -z --deleted > "$WORK/deleted"
LC_ALL=C sort -zu "$WORK/candidates" > "$WORK/candidates.sorted"
LC_ALL=C sort -zu "$WORK/deleted" > "$WORK/deleted.sorted"
LC_ALL=C comm -z -23 "$WORK/candidates.sorted" "$WORK/deleted.sorted" > "$WORK/files"
tar -C "$ROOT" --null --files-from "$WORK/files" -cf "$WORK/source.tar"
tar -C "$CHECKOUT" -xf "$WORK/source.tar"
test ! -e "$CHECKOUT/vendor"
test ! -e "$CHECKOUT/var/docker/local.env"

checksum=$(printf '%s' "$CHECKOUT" | cksum)
project=dm-${checksum%% *}
image=$project-dev:local
sh "$CHECKOUT/bin/dev" setup 0 > "$WORK/first-setup.log" 2>&1
images_built=1
app=$(docker ps --quiet --filter "label=com.docker.compose.project=$project" --filter label=com.docker.compose.service=app)
test -n "$app"
docker exec "$app" curl --fail --silent http://localhost:8080/ > "$WORK/home.html"
docker exec "$app" php bin/console about > "$WORK/versions.log"
docker exec "$app" php -r 'if (getenv("POSTGRES_PASSWORD") !== false || file_exists("/app/var/docker/local.env") || posix_geteuid() === 0) { exit(1); }'
docker exec "$app" php -r 'if (getenv("EVENT_TRANSPORT_DSN") !== "sync://") { exit(1); }'
original=$(cksum < "$CHECKOUT/var/docker/local.env")

# The ORM marker is in this disposable DEVELOPMENT checkout only, never the caller's DB.
docker exec "$app" php docker/tools/consumer-task.php create > "$WORK/marker.log"
sh "$CHECKOUT/bin/dev" setup > "$WORK/repeat-setup.log" 2>&1
test "$original" = "$(cksum < "$CHECKOUT/var/docker/local.env")"
sh "$CHECKOUT/bin/dev" down > "$WORK/down-up.log" 2>&1
sh "$CHECKOUT/bin/dev" up >> "$WORK/down-up.log" 2>&1
app=$(docker ps --quiet --filter "label=com.docker.compose.project=$project" --filter label=com.docker.compose.service=app)
docker exec "$app" php docker/tools/consumer-task.php read >> "$WORK/marker.log"
docker exec "$app" php bin/console app:architecture:check --database >> "$WORK/marker.log"

# Missing credentials must never be silently replaced while persistent state exists.
mv "$CHECKOUT/var/docker/local.env" "$WORK/settings.backup"
result=0
sh "$CHECKOUT/bin/dev" setup > "$WORK/missing-settings.log" 2>&1 || result=$?
sh "$ROOT/docker/tools/assert-settings-refusal.sh" missing "$CHECKOUT/var/docker/local.env" \
    "$WORK/settings.backup" "$WORK/missing-settings.log" "$result"
mv "$WORK/settings.backup" "$CHECKOUT/var/docker/local.env"
test "$original" = "$(cksum < "$CHECKOUT/var/docker/local.env")"

cp "$CHECKOUT/var/docker/local.env" "$WORK/settings.backup"
printf 'PROJECT_ID=%s\n' "$project" > "$CHECKOUT/var/docker/local.env"
cp "$CHECKOUT/var/docker/local.env" "$WORK/incomplete.baseline"
result=0
sh "$CHECKOUT/bin/dev" setup > "$WORK/incomplete-settings.log" 2>&1 || result=$?
sh "$ROOT/docker/tools/assert-settings-refusal.sh" incomplete "$CHECKOUT/var/docker/local.env" \
    "$WORK/incomplete.baseline" "$WORK/incomplete-settings.log" "$result"
mv "$WORK/settings.backup" "$CHECKOUT/var/docker/local.env"
test "$original" = "$(cksum < "$CHECKOUT/var/docker/local.env")"

# Harmless, known inputs prove recursive Docker context exclusions, not just absence by chance.
mkdir -p "$CHECKOUT/config/jwt"
for canary in build-context-canary.pem build-context-canary.key config/jwt/build-context-canary.pem config/jwt/build-context-canary.key; do
    printf '%s\n' 'NOT A KEY: build context exclusion canary' > "$CHECKOUT/$canary"
done

# Injection from the caller's environment must not change test targets or credentials.
DATABASE_URL=postgresql://wrong:wrong@invalid.invalid/app COMPOSE_PROJECT_NAME=wrong APP_SECRET=wrong \
    EVENT_TRANSPORT_DSN=doctrine://default \
    sh "$CHECKOUT/bin/dev" test > "$WORK/isolated-test.log" 2>&1
docker exec "$app" php docker/tools/consumer-task.php read >> "$WORK/marker.log"
docker exec "$app" php bin/console app:architecture:check --database >> "$WORK/marker.log"
test "$original" = "$(cksum < "$CHECKOUT/var/docker/local.env")"
printf '%s\n' 'Verified clean checkout, HTTP, repeat setup, persistence, missing/incomplete-settings refusal, dev/test and build-context isolation; cleaning up.'
