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

# Étape 1 : installer les vendors (sans scripts — code pas encore copié)
RUN APP_ENV=prod composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

# ── Étape 2 : copier le code applicatif ───────────────────────
COPY . .

# Étape 3 : autoloader optimisé + scripts Flex (code disponible maintenant)
RUN APP_ENV=prod composer dump-autoload \
    --optimize \
    --no-dev \
    --no-interaction \
    && APP_ENV=prod composer run-script post-install-cmd \
    --no-dev \
    --no-interaction \
    2>/dev/null || true

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
#Répertoire par defaut
WORKDIR /appli/apache_2.4/htdocs/symfony
