#!/usr/bin/env bash
# Runs on the server (as the "deploy" user) from the Deploy workflow.
# Usage: APP_IMAGE=ghcr.io/owner/prugna:<sha> [APP_NAME=Prugna] bash deploy.sh
set -euo pipefail

cd "$(dirname "$0")"
: "${APP_IMAGE:?APP_IMAGE is required}"
export APP_IMAGE

env_value() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- || true; }
ADMIN_EMAIL="$(env_value APP_ADMIN_EMAIL)"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@$(env_value APP_DOMAIN)}"

set_env() { sed -i "/^$1=/d" .env; printf '%s="%s"\n' "$1" "$2" >> .env; }

step() { printf '\n==> %s\n' "$*"; }

# Application name (interface, page titles, emails). The .env is created once by provision.sh
# and never replaced: the workflow passes the name, so renaming the app only takes a variable.
if [ -n "${APP_NAME:-}" ] && [ "$(env_value APP_NAME | tr -d '"')" != "$APP_NAME" ]; then
    previous="$(env_value APP_NAME | tr -d '"')"
    step "App name: $APP_NAME"
    set_env APP_NAME "$APP_NAME"
    if [ "$(env_value MAIL_FROM_NAME | tr -d '"')" = "$previous" ]; then
        set_env MAIL_FROM_NAME "$APP_NAME"
    fi
fi

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
