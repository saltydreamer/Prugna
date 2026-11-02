#!/bin/sh
set -e

# Allow "docker run image --flag" like the upstream FrankenPHP image.
if [ "${1#-}" != "$1" ]; then
    set -- frankenphp run "$@"
fi

# The storage volume may predate newer directories (e.g. dompdf's font cache).
mkdir -p storage/app/public storage/fonts storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs 2>/dev/null || true

# Cache configuration for the current environment variables.
case "${CONTAINER_ROLE:-}" in
    web)
        php artisan optimize --no-interaction
        ;;
    worker|scheduler)
        php artisan config:cache --no-interaction
        php artisan event:cache --no-interaction
        ;;
esac

exec "$@"
