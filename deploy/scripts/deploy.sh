#!/usr/bin/env bash
# Runs on the server (as the "deploy" user) from the Deploy workflow.
# Usage: APP_IMAGE=ghcr.io/owner/boilerplate:<sha> bash deploy.sh
set -euo pipefail

cd "$(dirname "$0")"
: "${APP_IMAGE:?APP_IMAGE is required}"
export APP_IMAGE

env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }
ADMIN_EMAIL="$(env_value APP_ADMIN_EMAIL)"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$(env_value APP_DOMAIN)}"

step() { printf '\n==> %s\n' "$*"; }

step "Pull $APP_IMAGE"
docker compose pull --quiet

step "Database"
docker compose up -d --wait mariadb

step "Migrations and permissions"
docker compose run --rm --no-deps app php artisan migrate --force --no-interaction
docker compose run --rm --no-deps app php artisan permissions:sync --no-interaction

step "Initial super admin (only if none exists)"
docker compose run --rm --no-deps app php artisan app:create-super-admin "$ADMIN_EMAIL" --if-none --no-interaction

step "Start containers"
docker compose up -d --remove-orphans

# Remember the running image so that manual "docker compose" commands use it.
sed -i '/^APP_IMAGE=/d' .env
echo "APP_IMAGE=${APP_IMAGE}" >> .env

step "Cleanup"
docker image prune -f > /dev/null

docker compose ps --format 'table {{.Service}}\t{{.Status}}'
