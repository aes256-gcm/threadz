#!/bin/sh
set -eu

export PGHOST="${DATABASE_HOST:-db}"
export PGPORT="${DATABASE_PORT:-5432}"
export PGDATABASE="${DATABASE_NAME:-threadline}"
export PGUSER="${DATABASE_USER:-threadline}"
export PGPASSWORD="${DATABASE_PASSWORD:-}"

until pg_isready -q; do
    sleep 1
done

psql -v ON_ERROR_STOP=1 -f /var/www/html/database/schema.sql
chown -R www-data:www-data /var/www/html/public/uploads

exec apache2-foreground
