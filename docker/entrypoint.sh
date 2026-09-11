#!/bin/sh
set -eu

if [ ! -w /app/var ]; then
    printf '%s\n' 'Runtime volume ownership mismatch. See README.md: recovery.' >&2
    exit 1
fi
mkdir -p /app/var/cache /app/var/log "$XDG_CONFIG_HOME" "$XDG_DATA_HOME" "$XDG_CACHE_HOME"

if [ "${1:-}" = frankenphp ]; then
    test -n "${APP_SECRET:-}" || { printf '%s\n' 'Run ./bin/dev setup first.' >&2; exit 1; }
    test -f vendor/autoload_runtime.php || { printf '%s\n' 'Dependencies missing. Run ./bin/dev setup.' >&2; exit 1; }
fi

exec "$@"
