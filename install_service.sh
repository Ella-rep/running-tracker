#!/bin/bash
set -e

#Vérifier que docker*-compose est installé
if [ ! -x /usr/libexec/docker/cli-plugins/docker-compose ]; then
    echo "Erreur : docker-compose n'existe pas ou n'est pas exécutable à l'emplacement /usr/libexec/docker/cli-plugins/docker-compose."
    exit 1
fi

# Lire les credentials de la ligne de commande
postgres_password=$1 
database_password=$2

# Vérifier si la variable d'envionnement BDD est définie
if [ -z "$postgres_password" ]; then
    echo "Erreur : Vous devez fournir postgres_password."
    exit 1
fi

# Vérifier si la variable d'envionnement BDD est définie
if [ -z "$database_password" ]; then
    echo "Erreur : Vous devez fournir database_password."
    exit 1
fi

# Lire la variable PATH_LOCAL depuis le fichier .env
PATH_LOCAL=$(grep '^PATH_LOCAL=' .env | cut -d '=' -f2 | tr -d '\r')


# Vérifier si PATH_LOCAL est défini
if [ -z "$PATH_LOCAL" ]; then
    echo "Erreur : La variable PATH_LOCAL n'est pas définie dans le fichier .env."
    exit 1
else
    PATH_LOCAL="$PATH_LOCAL/running-tracker"
fi


#Arborescence BDD
if [ ! -d "$PATH_LOCAL/running-tracker-db" ]; then
    mkdir "$PATH_LOCAL/running-tracker-db"
    #chown postgres:postgres "$PATH_LOCAL/running-tracker-db" -R
fi


# Démarrer les services Docker
if grep -q '^POSTGRES_PASSWORD=' .env.docker; then
    if grep -q '^POSTGRES_PASSWORD=$' .env.docker; then
        sed -i "s/^POSTGRES_PASSWORD=$/POSTGRES_PASSWORD=$postgres_password/" .env.docker
    else
        echo "POSTGRES_PASSWORD est déjà défini et non vide."
    fi
else
    echo "POSTGRES_PASSWORD=$postgres_password" >> .env.docker
fi

if grep -q '^DATABASE_PASSWORD=' .env.docker; then
    if grep -q '^DATABASE_PASSWORD=$' .env.docker; then
        sed -i "s/^DATABASE_PASSWORD=$/DATABASE_PASSWORD=$database_password/" .env.docker
    else
        echo "DATABASE_PASSWORD est déjà défini et non vide."
    fi
else
    echo "DATABASE_PASSWORD=$database_password" >> .env.docker
fi

/usr/libexec/docker/cli-plugins/docker-compose --env-file .env.docker up -d