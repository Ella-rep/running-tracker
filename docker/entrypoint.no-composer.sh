#!/bin/sh
set -e

cd /app
RUNTIME_ENV="${APP_ENV:-prod}"

echo "Skipping Composer install at startup"

DB_HOST="${DATABASE_HOST:-db}"
DB_PORT="${DATABASE_PORT:-5432}"

echo "Waiting for PostgreSQL on ${DB_HOST}:${DB_PORT}..."
until nc -z "$DB_HOST" "$DB_PORT"; do
    printf "."
    sleep 2
done
echo ""
echo "PostgreSQL ready"

DB_USER="${DATABASE_USER:-runner}"
DB_PASS="${DATABASE_PASSWORD:-runner}"
DB_NAME="${DATABASE_NAME:-postgres}"

if [ -z "${DATABASE_VERSION:-}" ]; then
    export DATABASE_VERSION="15.0.0"
fi

echo "Checking database ${DB_NAME}..."
if ! PGPASSWORD="$DB_PASS" psql \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d postgres \
    -tAc "SELECT 1 FROM pg_database WHERE datname='${DB_NAME}'" | grep -q 1; then
    echo "Creating database ${DB_NAME}"
    PGPASSWORD="$DB_PASS" createdb \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" \
        --template=template0 --encoding=UTF8 --locale=C \
        "$DB_NAME"
fi

echo "Running migrations..."
php bin/console doctrine:migrations:sync-metadata-storage --no-interaction || true

APP_TABLE_COUNT=$(PGPASSWORD="$DB_PASS" psql \
    -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    -tAc "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' AND table_name <> 'doctrine_migration_versions'")

if [ "${APP_TABLE_COUNT:-0}" -gt 0 ]; then
    echo "Existing schema detected: baseline all available migrations"
    PGPASSWORD="$DB_PASS" psql \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        -c "TRUNCATE TABLE doctrine_migration_versions" >/dev/null || true
    php bin/console doctrine:migrations:version --add --all --no-interaction || true
else
    PGPASSWORD="$DB_PASS" psql \
        -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
        -c "TRUNCATE TABLE doctrine_migration_versions" >/dev/null || true
fi

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

echo "Checking JWT keys..."
mkdir -p config/jwt
if [ ! -f config/jwt/private.pem ] || [ ! -f config/jwt/public.pem ]; then
    echo "Generating JWT keys"
    openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -pass pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
    openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:"${JWT_PASSPHRASE:-change_me_jwt_passphrase}"
fi
chown -R www-data:www-data config/jwt

echo "Configuring mail..."
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
    echo "SMTP legacy (msmtp) configured (${SMTP_HOST}:${SMTP_PORT:-587})"
elif [ -n "${MAILER_DSN:-}" ]; then
    echo "Symfony Mailer enabled via MAILER_DSN"
else
    echo "No mail transport configured (MAILER_DSN or SMTP_HOST missing)."
fi

echo "Installing assets..."
php bin/console assets:install public --symlink --relative --env="$RUNTIME_ENV" || \
php bin/console assets:install public --env="$RUNTIME_ENV"

echo "Preparing Symfony cache..."
mkdir -p var/cache var/log
rm -rf "var/cache/$RUNTIME_ENV"
mkdir -p "var/cache/$RUNTIME_ENV/twig" "var/cache/$RUNTIME_ENV/pools"
chown -R www-data:www-data var/
chmod -R ug+rwX var/

echo "Cache warmup..."
php bin/console cache:warmup --env="$RUNTIME_ENV"
chown -R www-data:www-data var/
chmod -R ug+rwX var/

echo "Starting PHP-FPM..."
php-fpm -D

echo "Waiting for PHP-FPM socket..."
until [ -S /run/php-fpm.sock ]; do sleep 0.2; done
echo "PHP-FPM socket ready"

echo "Starting Nginx..."
exec nginx -g "daemon off;"
