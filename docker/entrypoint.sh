#!/bin/sh
set -e

cd /app

echo "📚  Vérification des dépendances Composer..."
if [ ! -f vendor/autoload.php ] || [ ! -d vendor/twig/extra-bundle ]; then
    echo "➕  Installation des dépendances Composer (vendor manquant ou incomplet)"
    /usr/local/bin/composer install \
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
RUNTIME_ENV="${APP_ENV:-prod}"

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
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction || true

APP_TABLE_COUNT=$(PGPASSWORD="$DB_PASS" psql \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'doctrine_migration_versions'")

if [ "${APP_TABLE_COUNT:-0}" -gt 0 ]; then
    echo "ℹ️   Schéma existant détecté: baseline générique des migrations disponibles"
    PGPASSWORD="$DB_PASS" psql \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        -c "TRUNCATE TABLE doctrine_migration_versions" >/dev/null || true
    php bin/console doctrine:migrations:version --add --all --no-interaction || true
else
    # Base vide: on s'assure de rejouer les migrations depuis zéro.
    PGPASSWORD="$DB_PASS" psql \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        -c "TRUNCATE TABLE doctrine_migration_versions" >/dev/null || true
fi

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "🔐  Vérification des clés JWT..."
mkdir -p config/jwt
if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    echo "➕  Génération des clés JWT"
    openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
fi
chown -R www-data:www-data config/jwt

echo "✉️   Configuration SMTP (msmtp)..."
if [ -n "${SMTP_HOST:-}" ]; then
    SMTP_AUTH_MODE="off"
    if [ -n "${SMTP_USER:-}" ] && [ -n "${SMTP_PASSWORD:-}" ]; then
        SMTP_AUTH_MODE="on"
    fi

    mkdir -p /var/log
    touch /var/log/msmtp.log
    cat > /etc/msmtprc <<EOF
defaults
auth ${SMTP_AUTH_MODE}
tls ${SMTP_TLS:-on}
tls_starttls ${SMTP_STARTTLS:-on}
tls_trust_file /etc/ssl/certs/ca-certificates.crt
logfile /var/log/msmtp.log

account default
host ${SMTP_HOST}
port ${SMTP_PORT:-587}
from ${SMTP_FROM:-no-reply@runtracker.local}
user ${SMTP_USER:-}
password ${SMTP_PASSWORD:-}
EOF
    chmod 600 /etc/msmtprc
    echo "✅  SMTP configuré (${SMTP_HOST}:${SMTP_PORT:-587})"
else
    echo "⚠️   SMTP_HOST non défini: l'envoi d'email de reset échouera."
fi

echo "📦  Installation des assets..."
php bin/console assets:install public --symlink --relative --env="$RUNTIME_ENV" || \
php bin/console assets:install public --env="$RUNTIME_ENV"

echo "🧹  Préparation du cache Symfony..."
mkdir -p var/cache var/log
rm -rf "var/cache/$RUNTIME_ENV"
mkdir -p "var/cache/$RUNTIME_ENV/twig" "var/cache/$RUNTIME_ENV/pools"
chown -R www-data:www-data var/
chmod -R ug+rwX var/

echo "🔥  Cache warmup..."
php bin/console cache:warmup --env="$RUNTIME_ENV"
chown -R www-data:www-data var/
chmod -R ug+rwX var/


echo "🚀  Démarrage PHP-FPM..."
php-fpm -D

echo "⏳  Attente du socket PHP-FPM..."
until [ -S /run/php-fpm.sock ]; do sleep 0.2; done
echo "✅  Socket PHP-FPM prêt"

echo "🌐  Démarrage Nginx..."
exec nginx -g "daemon off;"