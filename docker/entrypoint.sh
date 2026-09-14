#!/bin/sh
set -eu
umask 077

if [ ! -w /app/var ]; then
    printf '%s\n' 'Runtime volume ownership mismatch. See README.md: recovery.' >&2
    exit 1
fi
mkdir -p /app/var/cache /app/var/log "$XDG_CONFIG_HOME" "$XDG_DATA_HOME" "$XDG_CACHE_HOME"
case "${APP_ENV:-dev}" in
    dev|test|prod) ;;
    *) printf '%s\n' 'Unsupported runtime environment.' >&2; exit 1 ;;
esac
mkdir -p "/app/var/sessions/${APP_ENV:-dev}" "/app/var/security/${APP_ENV:-dev}"
chmod 700 /app/var/sessions /app/var/security "/app/var/sessions/${APP_ENV:-dev}" "/app/var/security/${APP_ENV:-dev}"

if [ "${1:-}" = frankenphp ]; then
    test -n "${APP_SECRET:-}" || { printf '%s\n' 'Run ./bin/dev setup first.' >&2; exit 1; }
    test -f vendor/autoload_runtime.php || { printf '%s\n' 'Dependencies missing. Run ./bin/dev setup.' >&2; exit 1; }
fi

exec "$@"
