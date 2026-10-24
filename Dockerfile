# syntax=docker/dockerfile:1

FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
# If the lock drifts from what npm resolves on Alpine (e.g. the react peer of the
# optional @laravel/multiplex CLI), fall back to a plain install instead of failing.
RUN npm ci --no-audit --no-fund || npm install --no-audit --no-fund
COPY --from=dependencies /app/vendor ./vendor
COPY vite.config.js ./
COPY resources ./resources
COPY app ./app
RUN npm run build

FROM php:8.4-cli AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev openssh-client \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
COPY --from=assets /app/public/build ./public/build
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev \
    && sed -i 's/\r$//' docker-entrypoint.sh \
    && chmod +x docker-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["./docker-entrypoint.sh"]
