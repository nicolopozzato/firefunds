#!/bin/sh
# lando db-import var/dumps/<file>: replaces the dev database with a dump
# (pg_dump custom format, .sql or .sql.gz). Files are read from var/dumps/.
set -e

if [ -z "$1" ]; then
	echo 'Usage: lando db-import var/dumps/<file>'
	ls /app/var/dumps
	exit 1
fi

FILE="/app/var/dumps/$(basename "$1")"
[ -f "$FILE" ] || { echo "Not found: $FILE (put dumps in var/dumps/)"; exit 1; }

export PGUSER=app PGPASSWORD=app
dropdb --if-exists --force firefunds
createdb firefunds

case "$FILE" in
	*.sql.gz) gunzip -c "$FILE" | psql -q -v ON_ERROR_STOP=1 firefunds ;;
	*.sql) psql -q -v ON_ERROR_STOP=1 firefunds < "$FILE" ;;
	*) pg_restore --no-owner --no-acl --exit-on-error -d firefunds "$FILE" ;;
esac

echo "Imported $(basename "$FILE") into firefunds."
