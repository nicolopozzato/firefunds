#!/bin/sh
# lando db-dump: writes var/dumps/firefunds_<date>.dump (pg_dump custom format).
set -e
FILE="/app/var/dumps/firefunds_$(date +%Y%m%d_%H%M%S).dump"
PGPASSWORD=app pg_dump -U app -Fc --no-owner --no-acl firefunds > "$FILE"
chown "$(stat -c %u:%g /app/var/dumps)" "$FILE"
echo "Written var/dumps/$(basename "$FILE")"
