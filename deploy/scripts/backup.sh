#!/usr/bin/env bash
# Nightly database dump (installed in /etc/cron.d by provision.sh). Keeps 14 days.
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p backups

file="backups/db-$(date +%F-%H%M).sql.gz"

docker compose exec -T mariadb sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' | gzip > "$file"

find backups -name 'db-*.sql.gz' -mtime +14 -delete
echo "Backup: $file ($(du -h "$file" | cut -f1))"
