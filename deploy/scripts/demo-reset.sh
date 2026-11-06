#!/usr/bin/env bash
# Turns this server into the demonstration instance: ERASES the database and the uploaded files and
# fills them with three years of work of a fictitious freelancer (php artisan app:demo-seed).
# Runs on the VPS from the "Reset demo" workflow, after its backup. Uploaded and run as a file: a
# script fed through stdin (ssh … 'bash -s') is swallowed by the first `docker compose exec`.
set -euo pipefail

cd /srv/boilerplate
unset APP_DOMAIN

step() { printf '\n==> %s\n' "$*"; }
env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | tr -d '"' || true; }

sed -i '/^APP_DEMO=/d' .env && echo 'APP_DEMO=true' >> .env
admin="$(env_value APP_ADMIN_EMAIL)"
admin="${admin:-admin@$(env_value APP_DOMAIN)}"

step "Maintenance mode"
docker compose exec -T app php artisan down || true
docker compose stop worker scheduler

step "Uploaded files of the real data"
docker compose exec -T app sh -c 'cd /app/storage/app && rm -rf private/* public/*'

step "Empty database and demo data"
docker compose run --rm --no-deps app php artisan migrate:fresh --force --no-interaction
docker compose run --rm --no-deps app php artisan permissions:sync --no-interaction
docker compose run --rm --no-deps app php artisan app:demo-seed --force --no-interaction
docker compose run --rm --no-deps app php artisan app:create-super-admin "$admin" --if-none --no-interaction

step "Restart with APP_DEMO=true"
docker compose up -d --force-recreate app worker scheduler
docker compose exec -T app php artisan up || true
docker compose ps --format 'table {{.Service}}\t{{.Status}}'

echo "Demo data loaded."
