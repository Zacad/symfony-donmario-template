#!/bin/sh
set -eu
umask 077

# Native cryptography/filesystem contracts in disposable container storage only.
tool=$(pwd)/docker/tools/jwt-keys.php
work=$(mktemp -d /tmp/jwt-key-contract-XXXXXXXX)
lock_pid=
cleanup() {
    if [ -n "$lock_pid" ]; then kill "$lock_pid" 2>/dev/null || true; wait "$lock_pid" 2>/dev/null || true; fi
    rm -rf "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$work/keys" "$work/other" "$work/lost" "$work/partial"
keys() { php "$tool" "$1" "$work/keys" "$work/completed"; }
refuse() {
    if "$@" > "$work/refusal.log" 2>&1; then
        printf '%s\n' 'JWT contract expected safe refusal.' >&2
        exit 1
    fi
}
keys initialize
keys validate
cp "$work/keys/active/public.pem" "$work/original.public"
cp "$work/completed" "$work/original.marker"
keys initialize
cmp "$work/original.public" "$work/keys/active/public.pem"
cmp "$work/original.marker" "$work/completed"
# Existing Subtask 6 installations provision once; interrupted completion retains
# the valid volume instead of replacing keys when restoring the completed marker.
rm "$work/completed"
keys initialize
cmp "$work/original.public" "$work/keys/active/public.pem"
cmp "$work/original.marker" "$work/completed"
refuse php "$tool" initialize "$work/lost" "$work/completed"
test ! -e "$work/lost/active"
: > "$work/keys/.identity"
: > "$work/completed"
refuse keys initialize
cp "$work/original.marker" "$work/keys/.identity"
cp "$work/original.marker" "$work/completed"
mkdir "$work/partial/generations"
refuse php "$tool" initialize "$work/partial" "$work/partial-marker"
test ! -e "$work/partial/active"

php "$tool" initialize "$work/other" "$work/other-marker"
refuse php "$tool" initialize "$work/other" "$work/completed"
test "$(readlink "$work/keys/current")" != "$(readlink "$work/other/current")"
cp "$work/other/active/public.pem" "$work/keys/active/public.pem"
refuse keys initialize
cp "$work/original.public" "$work/keys/active/public.pem"
chmod 644 "$work/keys/active/private.pem"
refuse keys initialize
chmod 600 "$work/keys/active/private.pem"
mv "$work/keys/active/private.pem" "$work/private.backup"
refuse keys initialize
mv "$work/private.backup" "$work/keys/active/private.pem"
keys validate

php -r '$lock = fopen($argv[1], "c"); if (!flock($lock, LOCK_EX)) { exit(1); } file_put_contents($argv[2], "ready"); sleep(30);' \
    "$work/keys/.lock" "$work/lock-ready" &
lock_pid=$!
tries=0
while [ ! -f "$work/lock-ready" ]; do
    tries=$((tries + 1)); test "$tries" -lt 100
    sleep 0.1
done
refuse keys rotate
kill "$lock_pid"
wait "$lock_pid" 2>/dev/null || true
lock_pid=

keys rotate
cmp "$work/original.public" "$work/keys/previous/public.pem"
if cmp -s "$work/original.public" "$work/keys/active/public.pem"; then exit 1; fi
test ! -e "$work/keys/previous/private.pem"
test "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));' "$work/keys/verification.json")" = 1
refuse keys rotate
cp "$work/keys/active/public.pem" "$work/rotated.public"
keys retire
cmp "$work/rotated.public" "$work/keys/active/public.pem"
test ! -e "$work/keys/previous/public.pem"
keys retire
keys rotate
keys rotate-emergency
test ! -e "$work/keys/previous/public.pem"
test "$(php -r 'echo count(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));' "$work/keys/verification.json")" = 0
test "$(php -r 'echo count(array_diff(scandir($argv[1]), [".", ".."]));' "$work/keys/generations")" = 1
cmp "$work/original.marker" "$work/completed"
keys validate
printf '%s\n' 'JWT key contracts passed: RSA3072 retention, independent identity, lost/partial/mismatched/unsafe keys refused, lock exclusion, atomic rotation, one old public key, retirement and emergency no-old-trust.'
