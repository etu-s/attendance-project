# syntax=docker/dockerfile:1
#
# AMS images, built from the repository root (context `..` in docker/compose.yaml):
#   target `app` - PHP 8.4 (php-fpm by default); the same image runs Horizon,
#                  the scheduler, Reverb and the one-shot `init` step.
#   target `web` - nginx serving the built SPA and passing /api, /sanctum,
#                  /broadcasting, /horizon and /up to php-fpm; /app (WebSockets)
#                  to Reverb.

ARG PHP_VERSION=8.4
ARG NODE_VERSION=24
ARG NGINX_VERSION=1.28
ARG COMPOSER_VERSION=2.8

FROM composer:${COMPOSER_VERSION} AS composer-bin

# ---------------------------------------------------------------- PHP runtime
FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

# pcntl/posix: Horizon and Reverb signal handling. zip: XLSX exports (openspout).
# intl: number/date formatting. redis: queues, cache, rate limits.
# pdo_sqlite, mbstring, dom, xmlreader, fileinfo ship with the base image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu72 libzip4 libicu-dev libzip-dev unzip; \
    docker-php-ext-install -j"$(nproc)" bcmath intl opcache pcntl zip; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apt-get purge -y --auto-remove libicu-dev libzip-dev; \
    rm -rf /var/lib/apt/lists/* /tmp/pear; \
    cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/php.ini "$PHP_INI_DIR/conf.d/zz-ams.ini"
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-ams.conf

WORKDIR /var/www/html

# ------------------------------------------------------- Composer dependencies
FROM php-base AS vendor
COPY --from=composer-bin /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

FROM vendor AS app-build
COPY . .
# Runs package:discover, which writes bootstrap/cache/{packages,services}.php for production packages only.
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-interaction \
    && rm /usr/bin/composer

# ------------------------------------------------------------------ app image
FROM php-base AS app

# Code stays root-owned (read-only for the app); only runtime directories are writable.
COPY --from=app-build /var/www/html /var/www/html
COPY docker/php/entrypoint.sh /usr/local/bin/ams-entrypoint

RUN set -eux; \
    chmod 0755 /usr/local/bin/ams-entrypoint; \
    mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs \
        /var/www/data/tenants /var/www/backups; \
    chown -R www-data:www-data storage bootstrap/cache /var/www/data /var/www/backups

USER www-data
ENTRYPOINT ["ams-entrypoint"]
CMD ["php-fpm"]

# ---------------------------------------------------------------- SPA build
FROM node:${NODE_VERSION}-alpine AS frontend
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./

# Vite inlines VITE_* at build time, from .env files and from the process
# environment alike, so the build args are named SPA_* and only non-empty ones
# are written as VITE_*. Left out, the SPA's own fallbacks apply (e.g. the
# WebSocket host defaults to the page's host, which nginx proxies to Reverb).
ARG SPA_CENTRAL_DOMAINS=localhost,127.0.0.1
ARG SPA_REVERB_APP_KEY=
ARG SPA_REVERB_HOST=
ARG SPA_REVERB_PORT=
ARG SPA_REVERB_SCHEME=
RUN set -eu; \
    : > .env.production.local; \
    for name in CENTRAL_DOMAINS REVERB_APP_KEY REVERB_HOST REVERB_PORT REVERB_SCHEME; do \
        value="$(printenv "SPA_$name" || true)"; \
        if [ -n "$value" ]; then echo "VITE_$name=$value" >> .env.production.local; fi; \
    done; \
    npm run build

# ------------------------------------------------------------------ web image
FROM nginx:${NGINX_VERSION}-alpine AS web
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=frontend /app/dist /usr/share/nginx/html
