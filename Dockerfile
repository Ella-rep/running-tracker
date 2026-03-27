FROM php:8.2-fpm-alpine AS base

# System deps
RUN apk add --no-cache \
    nginx \
    openssl \
    postgresql-dev \
    unzip \
    git \
    supervisor \
    && docker-php-ext-install pdo pdo_pgsql opcache

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

# Install PHP deps first (layer cache)
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy full app
COPY . .

# Finish composer
RUN composer dump-autoload --optimize --no-dev

# Nginx config
COPY docker/nginx.conf /etc/nginx/nginx.conf

# Supervisor config (runs php-fpm + nginx)
COPY docker/supervisord.conf /etc/supervisord.conf

# Entrypoint (migrations + JWT keys + cache warmup)
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Permissions
RUN mkdir -p var/cache var/log config/jwt \
    && chown -R www-data:www-data var config/jwt

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
