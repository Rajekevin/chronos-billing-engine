# syntax=docker/dockerfile:1
FROM php:8.4-fpm-alpine AS base

# OWASP A06 (Vulnerable & Outdated Components) / A05 (Security Misconfig):
# pin extensions, run as non-root, no dev tools in the final image.
RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        git \
        unzip \
    && docker-php-ext-install pdo pdo_pgsql intl opcache \
    && apk del git unzip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Non-root user (OWASP A05): never run the app as root inside the container.
RUN addgroup -g 1000 chronos && adduser -D -u 1000 -G chronos chronos

WORKDIR /var/www/app

COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p var/cache var/log \
    && chown -R chronos:chronos /var/www/app

USER chronos

EXPOSE 9000
CMD ["php-fpm"]
