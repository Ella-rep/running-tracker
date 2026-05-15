# ── Stage 1 : vendor (composer install) ──────────────────────────────────────
#FROM composer:2 AS vendor

# Composer


# ── Image finale (repo, sans proxy) ──────────────────────────────────────────
FROM php:8.4-fpm

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json ./
# Installer sans scripts (pas de kernel au build)
# SYMFONY_ENV=prod évite que Flex pose des questions interactives
ENV SYMFONY_ENV=prod
RUN mkdir -p vendor


# Packages système
RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        nginx \
        netcat-openbsd \
        postgresql-client \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        libxml2-dev \
        pkg-config \
        g++ \
        make \
        autoconf \
        unzip \
        git; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl xml opcache; \
    docker-php-ext-enable xml opcache; \
    apt-get purge -y --auto-remove g++ make autoconf pkg-config; \
    rm -rf /var/lib/apt/lists/* /tmp/*

COPY . .



# OPcache
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
} > /usr/local/etc/php/conf.d/opcache.ini

# PHP-FPM socket
COPY docker/php-fpm-socket.conf /usr/local/etc/php-fpm.d/zz-socket.conf

# Nginx
RUN mkdir -p /run/nginx
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
RUN rm -f /etc/nginx/sites-enabled/default

WORKDIR /app

# Code source complet
COPY . .


# Permissions
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/ \
    && chown -R www-data:www-data public/

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]