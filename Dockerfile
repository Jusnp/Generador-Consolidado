# syntax=docker/dockerfile:1

# Entorno de desarrollo Docker + PostgreSQL de json-excel (SIRUTA).
# Es independiente de Laragon/MariaDB; ver DOCKER_POSTGRESQL.md.

FROM composer:2.8.11 AS composer

FROM php:8.3.26-fpm-bookworm AS app

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY docker/php/json-excel.ini /usr/local/etc/php/conf.d/zz-json-excel.ini

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader

COPY . .

RUN composer dump-autoload --no-interaction \
    && mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

CMD ["php-fpm", "-F"]
