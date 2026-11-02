#!/bin/sh
set -e

# Allow "docker run image --flag" like the upstream FrankenPHP image.
if [ "${1#-}" != "$1" ]; then
    set -- frankenphp run "$@"
fi

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
