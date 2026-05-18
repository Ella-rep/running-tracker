# ── Stage 1 : installer les dépendances ──────────────────────────────────────
FROM composer:2 AS vendor

ARG HTTP_PROXY=
ARG HTTPS_PROXY=
ARG NO_PROXY=localhost,127.0.0.1,db

ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}


WORKDIR /app

# Copier uniquement composer.json pour résoudre les dépendances
COPY composer.json ./

# Installer sans scripts (pas de kernel au build)
# SYMFONY_ENV=prod évite que Flex pose des questions interactives
ENV SYMFONY_ENV=prod
RUN mkdir -p vendor

# ── Stage 2 : image finale ────────────────────────────────────────────────────
FROM php:8.4-fpm

# Composer binaire disponible dans l'image finale
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ARG HTTP_PROXY=
ARG HTTPS_PROXY=
ARG NO_PROXY=localhost,127.0.0.1,db
ENV HTTP_PROXY=${HTTP_PROXY} \
    HTTPS_PROXY=${HTTPS_PROXY} \
    NO_PROXY=${NO_PROXY} \
    http_proxy=${HTTP_PROXY} \
    https_proxy=${HTTPS_PROXY} \
    no_proxy=${NO_PROXY}

# Packages système
RUN set -eux; \
    export DEBIAN_FRONTEND=noninteractive; \
        rm -f /etc/apt/apt.conf.d/99proxy; \
        normalize_proxy() { \
            v="$1"; \
            case "$v" in \
                http://*|https://*|"") printf '%s' "$v" ;; \
                *) printf 'http://%s' "$v" ;; \
            esac; \
        }; \
        HP="$(normalize_proxy "${HTTP_PROXY:-}")"; \
        HPS="$(normalize_proxy "${HTTPS_PROXY:-$HP}")"; \
        if [ -n "$HP" ]; then printf 'Acquire::http::Proxy "%s";\n' "$HP" > /etc/apt/apt.conf.d/99proxy; fi; \
        if [ -n "$HPS" ]; then printf 'Acquire::https::Proxy "%s";\n' "$HPS" >> /etc/apt/apt.conf.d/99proxy; fi; \
        if ! apt-get -o Acquire::ForceIPv4=true -o Acquire::Retries=5 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 update; then \
            echo 'ERROR: apt-get update failed. If your corporate proxy requires auth, set HTTP_PROXY/HTTPS_PROXY with credentials (http://user:pass@host:port).'; \
            exit 1; \
        fi; \
        apt-get -o Acquire::ForceIPv4=true -o Acquire::Retries=5 -o Acquire::http::Timeout=30 -o Acquire::https::Timeout=30 install -y --no-install-recommends \
        ca-certificates \
        nginx \
        netcat-openbsd \
        postgresql-client \
        msmtp \
        msmtp-mta \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        pkg-config \
        g++ \
        make \
        autoconf; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql zip intl opcache; \
    docker-php-ext-enable opcache; \
    apt-get purge -y --auto-remove g++ make autoconf pkg-config; \
    rm -rf /var/lib/apt/lists/* /tmp/*

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

# Autoloader
# Utilise le vendor déjà présent dans le workspace (mode sans réseau)

# Permissions
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data var/ \
    && chown -R www-data:www-data public/

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]