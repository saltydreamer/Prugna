#!/usr/bin/env bash
# Nightly backup (installed in /etc/cron.d by provision.sh): database dump and uploaded files
# (documents, logos). Keeps 14 days.
set -euo pipefail

cd "$(dirname "$0")"
mkdir -p backups

stamp="$(date +%F-%H%M)"
file="backups/db-${stamp}.sql.gz"

docker compose exec -T mariadb sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines --triggers "$MARIADB_DATABASE"' | gzip > "$file"
echo "Backup: $file ($(du -h "$file" | cut -f1))"

# Uploaded files live in the "storage" volume: private documents and public files (logo).
files="backups/files-${stamp}.tar.gz"
docker compose exec -T app sh -c 'cd /app/storage/app && tar -czf - private public 2>/dev/null || true' > "$files"
echo "Backup: $files ($(du -h "$files" | cut -f1))"

find backups \( -name 'db-*.sql.gz' -o -name 'files-*.tar.gz' \) -mtime +14 -delete
