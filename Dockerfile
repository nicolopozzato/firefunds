# syntax=docker/dockerfile:1

# One image for the whole app: FrankenPHP serves the Symfony API under /api
# and the built React app for everything else. Service configs live in
# docker/<service>/.
#
#   dev   used by Lando (.lando.yml), code bind-mounted
#   prod  the image published to ghcr.io/nicolopozzato/firefunds
#         (run by compose.yml)

ARG PHP_VERSION=8.4
ARG FRANKENPHP_VERSION=1.13
ARG NODE_VERSION=22


# ---------------------------------------------------------------- base ----
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-trixie AS base

WORKDIR /app/backend

RUN apt-get update \
    && apt-get install -y --no-install-recommends acl file git unzip postgresql-client \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
        @composer \
        apcu \
        bcmath \
        intl \
        opcache \
        pdo_pgsql \
        zip

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_INI_SCAN_DIR=":$PHP_INI_DIR/app.conf.d" \
    SERVER_NAME=":8000" \
    APP_TIMEZONE=Europe/Rome

COPY docker/frankenphp/conf.d/10-app.ini $PHP_INI_DIR/app.conf.d/
COPY docker/frankenphp/Caddyfile /etc/frankenphp/Caddyfile
COPY --chmod=755 docker/frankenphp/docker-entrypoint.sh /usr/local/bin/docker-entrypoint

ENTRYPOINT ["docker-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

EXPOSE 8000


# ----------------------------------------------------------------- dev ----
FROM base AS dev

ARG USER_ID=1000
ARG GROUP_ID=1000

ENV APP_ENV=dev \
    XDEBUG_MODE=off

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini" \
    && install-php-extensions xdebug

COPY docker/frankenphp/conf.d/20-app.dev.ini $PHP_INI_DIR/app.conf.d/

# Same uid/gid as the host user, so files written in the bind mount
# (vendor/, var/) stay yours. The container starts as root (Lando's own
# setup needs it); docker-entrypoint then switches to APP_USER.
RUN groupadd -g ${GROUP_ID} app \
    && useradd -m -u ${USER_ID} -g ${GROUP_ID} -s /bin/bash app \
    && chown -R app:app /data/caddy /config/caddy /app

ENV APP_USER=app


# ------------------------------------------------------------ frontend ----
FROM node:${NODE_VERSION}-alpine AS frontend

WORKDIR /app/frontend

COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci

COPY frontend/ ./
RUN npm run build


# ---------------------------------------------------------------- prod ----
FROM base AS prod

ENV APP_ENV=prod \
    APP_SHARE_DIR=/app/backend/var/share

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/frankenphp/conf.d/20-app.prod.ini $PHP_INI_DIR/app.conf.d/

# Dependencies first, so a code-only change reuses this layer.
COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-cache --prefer-dist --no-dev --no-autoloader --no-scripts --no-progress

COPY backend/ ./
RUN rm -rf var/* config/jwt/*.pem .env.local .env.*.local \
    && composer dump-autoload --classmap-authoritative --no-dev \
    && composer dump-env prod \
    && composer run-script --no-dev post-install-cmd \
    && chmod +x bin/console

COPY --from=frontend /app/frontend/dist /app/frontend/dist

ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}
