#!/usr/bin/env bash
# Replaces the database and the uploaded files of this stack with a backup made by backup.sh
# (e.g. the data copied from another server by the "Copy data to the internal server" workflow).
# Usage: ./restore.sh backups/db-<stamp>.sql.gz [backups/files-<stamp>.tar.gz]
set -euo pipefail

cd "$(dirname "$0")"
db="${1:?Usage: ./restore.sh backups/db-<stamp>.sql.gz [backups/files-<stamp>.tar.gz]}"
files="${2:-}"
test -s "$db" || { echo "Missing or empty dump: $db" >&2; exit 1; }

step() { printf '\n==> %s\n' "$*"; }

step "Stop the app (database stays up)"
docker compose up -d --wait mariadb
docker compose stop app worker scheduler > /dev/null 2>&1 || true

step "Database from $db"
docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$MARIADB_DATABASE\`.* TO \`$MARIADB_USER\`@\`%\`;"'
gunzip -c "$db" | docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'

if [ -n "$files" ]; then
    step "Uploaded files from $files"
    test -s "$files" || { echo "Missing or empty archive: $files" >&2; exit 1; }
    docker compose run --rm --no-deps -T app sh -c 'cd /app/storage/app && rm -rf private public && tar -xzf -' < "$files"
fi

step "Migrations (the backup may come from an older version)"
docker compose run --rm --no-deps app php artisan migrate --force --no-interaction

step "Start containers"
# Recreated: the .env (e.g. the APP_KEY of the copied data) is read when a container is created.
docker compose up -d --force-recreate --remove-orphans app worker scheduler
docker compose ps --format 'table {{.Service}}\t{{.Status}}'
