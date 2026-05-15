#!/bin/sh
set -e

cd /app

echo "📚  Vérification des dépendances Composer..."
if [ ! -f vendor/autoload.php ] || [ ! -d vendor/twig/extra-bundle ]; then
    echo "➕  Installation des dépendances Composer (vendor manquant ou incomplet)"
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader \
        --classmap-authoritative
fi

echo "⏳  Attente de PostgreSQL sur db:5432..."
until nc -z db 5432; do
    printf "."
    sleep 2
done
echo ""
echo "✅  PostgreSQL disponible"

DB_HOST="${DATABASE_HOST:-db}"
DB_PORT="${DATABASE_PORT:-5432}"
DB_USER="${DATABASE_USER:-runner}"
DB_PASS="${DATABASE_PASSWORD:-runner}"
DB_NAME="${DATABASE_NAME:-postgres}"

echo "🧱  Vérification base ${DB_NAME}..."
if ! PGPASSWORD="$DB_PASS" psql \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres \
    -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
    echo "➕  Création base ${DB_NAME}"
    PGPASSWORD="$DB_PASS" createdb \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" \
        --template=template0 --encoding=UTF8 --locale=C \
        "$DB_NAME"
fi

echo "🗄️   Migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "🔐  Vérification des clés JWT..."
mkdir -p config/jwt
if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    echo "➕  Génération des clés JWT"
    openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
fi
chown -R www-data:www-data config/jwt

echo "📦  Installation des assets..."
php bin/console assets:install public --symlink --relative --env=prod || \
php bin/console assets:install public --env=prod

echo "🔥  Cache warmup..."
php bin/console cache:warmup --env=prod
chown -R www-data:www-data var/


echo "🚀  Démarrage PHP-FPM..."
php-fpm -D

echo "⏳  Attente du socket PHP-FPM..."
until [ -S /run/php-fpm.sock ]; do sleep 0.2; done
echo "✅  Socket PHP-FPM prêt"

echo "🌐  Démarrage Nginx..."
exec nginx -g "daemon off;"