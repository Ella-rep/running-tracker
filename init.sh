#!/bin/bash
set -e
echo "Initialisation de la base de données avec le mot de passe : $DATABASE_PASSWORD"

# Création de la base de données
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<-EOSQL
    CREATE USER runner WITH PASSWORD '$DATABASE_PASSWORD';
    CREATE DATABASE running-tracker WITH OWNER = postgres ENCODING = 'UTF8' LC_COLLATE = 'en_US.utf8' LC_CTYPE = 'en_US.utf8' CONNECTION LIMIT = -1;
    GRANT ALL PRIVILEGES ON DATABASE running-tracker TO runner;
    GRANT TEMPORARY, CONNECT ON DATABASE running-tracker TO PUBLIC;
    GRANT ALL ON DATABASE running-tracker TO postgres;
    ALTER DATABASE running-tracker OWNER TO runner;
EOSQL