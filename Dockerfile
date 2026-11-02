# syntax=docker/dockerfile:1

# Production image: FrankenPHP (PHP 8.4 + Caddy with automatic HTTPS).
# The same image runs the web server, the queue worker and the scheduler
# (see deploy/compose.yaml).

ARG PHP_VERSION=8.4

# --- PHP dependencies -------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

# --- Frontend assets --------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund

COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --- Runtime ----------------------------------------------------------------
FROM dunglas/frankenphp:1-php${PHP_VERSION} AS app

RUN install-php-extensions pdo_mysql intl gd zip pcntl bcmath exif opcache \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY deploy/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
COPY deploy/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 deploy/docker-entrypoint.sh /usr/local/bin/app-entrypoint

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

WORKDIR /app

COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build

RUN php artisan package:discover --ansi \
    && ln -sfn /app/storage/app/public /app/public/storage \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs /data /config \
    && chown -R www-data:www-data storage bootstrap/cache /data /config

USER www-data

EXPOSE 80 443 443/udp

ENTRYPOINT ["app-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile", "--adapter", "caddyfile"]
