FROM php:8.2-fpm-alpine AS base

# System deps
RUN apk add --no-cache \
    nginx \
    openssl \
    postgresql-dev \
    unzip \
    git \
    supervisor \
    bash \ 
    openrc \
    rsync \
    mlocate \
    netcat-openbsd \
    && docker-php-ext-install pdo pdo_pgsql opcache
# Composer ──────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

# ── Étape 1 : dépendances PHP (layer cache si composer.json inchangé) ──
COPY composer.json composer.lock* ./

WORKDIR /var/www/app

# ── Étape 1 : dépendances PHP (layer cache si composer.json inchangé) ──
COPY composer.json composer.lock* ./

# Fix git "dubious ownership"
RUN git config --global --add safe.directory /app \
    && git config --global --add safe.directory /var/www/app

# ── Étape 2 : copier le code applicatif complet ───────────────
# (nécessaire avant composer install pour que Flex écrive les configs)
COPY . .

# Étape 3 : installation complète avec autoloader optimisé
# SYMFONY_DEPRECATIONS_HELPER désactive les warnings qui font échouer le build
RUN COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=prod \
    SYMFONY_DEPRECATIONS_HELPER=disabled \
    composer install \
    --no-dev \
    --optimize-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-audit

# ── Configuration serveurs ────────────────────────────────────
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

# ── Entrypoint ────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# ── Permissions ───────────────────────────────────────────────
RUN mkdir -p var/cache var/log config/jwt \
    && chown -R www-data:www-data var config/jwt

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
#ENTRYPOINT ["/sbin/init"]

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
#Répertoire par defaut
#WORKDIR /appli/apache_2.4/htdocs/symfony
