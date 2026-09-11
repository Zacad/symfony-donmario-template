#!/bin/sh
set -eu

# Values reach psql through its quoting mechanism, never shell-interpolated SQL.
# Keep stderr private on init failure: PostgreSQL can echo password-bearing SQL.
if ! PGOPTIONS='-c log_statement=none -c log_min_error_statement=panic' \
    psql --username "$POSTGRES_USER" --dbname postgres --set ON_ERROR_STOP=1 \
    --set app_database="$APP_DATABASE_NAME" \
    > /tmp/application-init.log 2>&1 <<'SQL'
\getenv app_password APP_DATABASE_PASSWORD
CREATE ROLE app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'app_password';
CREATE DATABASE :"app_database" OWNER app;
SQL
then
    rm -f /tmp/application-init.log
    printf '%s\n' 'Application database initialization failed. Restore initialization credentials; see README recovery.' >&2
    exit 1
fi
rm -f /tmp/application-init.log
