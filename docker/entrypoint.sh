#!/bin/sh
set -e

APP_DIR="/var/www/app"
JWT_DIR="${APP_DIR}/config/jwt"

echo "=== Running Tracker — démarrage ==="

# ── 1. Générer les clés JWT si absentes ──────────────────────
if [ ! -f "${JWT_DIR}/private.pem" ]; then
    echo "[JWT] Génération des clés RSA…"
    mkdir -p "${JWT_DIR}"
    openssl genpkey \
        -algorithm RSA \
        -out "${JWT_DIR}/private.pem" \
        -pkeyopt rsa_keygen_bits:4096 \
        -pass "pass:${JWT_PASSPHRASE:-changeme}" \
        2>/dev/null
    openssl pkey \
        -in "${JWT_DIR}/private.pem" \
        -out "${JWT_DIR}/public.pem" \
        -pubout \
        -passin "pass:${JWT_PASSPHRASE:-changeme}" \
        2>/dev/null
    chown -R www-data:www-data "${JWT_DIR}"
    chmod 600 "${JWT_DIR}/private.pem"
    chmod 644 "${JWT_DIR}/public.pem"
    echo "[JWT] Clés générées."
else
    echo "[JWT] Clés existantes trouvées."
fi

# ── 2. Attendre PostgreSQL ────────────────────────────────────
# Extraire host et port depuis DATABASE_URL
DB_HOST=$(echo "${DATABASE_URL}" | sed -n 's|.*@\([^:/]*\).*|\1|p')
DB_PORT=$(echo "${DATABASE_URL}" | sed -n 's|.*:\([0-9]*\)/.*|\1|p')
DB_HOST=${DB_HOST:-db}
DB_PORT=${DB_PORT:-5432}

echo "[DB] Attente de PostgreSQL sur ${DB_HOST}:${DB_PORT}…"
RETRIES=30
until nc -z "${DB_HOST}" "${DB_PORT}" 2>/dev/null; do
    RETRIES=$((RETRIES - 1))
    if [ $RETRIES -le 0 ]; then
        echo "[DB] ERREUR : PostgreSQL inaccessible après 30 tentatives."
        exit 1
    fi
    echo "[DB] Pas encore prêt (${RETRIES} tentatives restantes)…"
    sleep 2
done
# Attendre que PostgreSQL accepte vraiment les connexions (pas juste le port)
sleep 1
echo "[DB] PostgreSQL prêt."

# ── 3. Migrations ─────────────────────────────────────────────
echo "[DB] Application des migrations…"
php "${APP_DIR}/bin/console" doctrine:migrations:migrate --no-interaction --allow-no-migration
echo "[DB] Migrations OK."

# ── 4. Cache Symfony ──────────────────────────────────────────
echo "[Cache] Préchauffage du cache…"
php "${APP_DIR}/bin/console" cache:warmup --env=prod 2>/dev/null || true
echo "[Cache] OK."

echo "=== Démarrage des services (php-fpm + nginx) ==="
exec "$@"
