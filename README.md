# 🏃 Running Tracker — Symfony 7 + API Platform + PostgreSQL

Application de suivi running avec :
- **Backend** : Symfony 7 + API Platform 3 (REST auto-généré, doc Swagger)
- **Auth** : JWT via LexikJWTAuthenticationBundle
- **BDD** : PostgreSQL 15 + Doctrine ORM + migrations automatiques
- **Frontend** : Twig (pages) + JS vanilla (interactions via API + widgets dashboard)
- **Déploiement** : Docker Compose (Debian + PHP 8.4-FPM + Nginx + PostgreSQL)

## 🆕 Nouveautés récentes (mai 2026)

- Dashboard modulaire avec widgets activables/désactivables par utilisateur
- Carte **Temps projetés** (5/10/21/42 km) avec couleurs différenciées
- Bloc **Charge d'entraînement** (7j/base/écart) avec indicateurs visuels
- Calendrier mensuel enrichi (séances, courses, événements perso)
- Correctifs UI sur le calendrier (pas de débordement entre cases, détails plus compacts)
- Chargement initial optimisé : rendu plus rapide, widgets lourds chargés en différé

## ✨ Fonctionnalités

### 📊 Métriques de course
- Suivi des sorties running avec distance, durée, vitesse moyenne
- Calcul automatique du rythme (min/km) et de la vitesse (km/h)
- Historique complet des performances avec graphiques d'évolution
- Statistiques mensuelles/annuelles (distance totale, temps cumulé, nombre de sorties)
- Projections de temps (5 km, 10 km, semi, marathon) basées sur les dernières sorties

### 🎯 Plans d'entraînement
- Création de plans personnalisés avec objectifs de course
- Suivi des séances d'entraînement (fractionné, endurance, récupération)
- Coche automatique des séances réalisées
- Progression visualisée avec indicateurs de réalisation
- Vue calendrier mensuelle des séances prévues et réalisées

### 🏁 Gestion des courses
- Enregistrement des courses passées et futures
- Suivi des performances par course (temps, classement, conditions météo)
- Objectifs de course avec préparation personnalisée
- Historique des participations et résultats

### 💡 Conseils d'entraînement
- Conseils adaptés basés sur les données de performance
- Recommandations de récupération selon l'intensité des séances
- Alertes sur la surcharge d'entraînement
- Suggestions d'amélioration du rythme et de la forme physique
- Conseils météo contextuels (ville personnalisable)

### 🔐 Sécurité et confidentialité
- Comptes utilisateurs isolés avec authentification JWT
- Toutes les données personnelles et de performance sont privées
- Chiffrement des mots de passe et des tokens de session

---

## Structure du projet


```
running-tracker/
├── bin/console
├── config/
│   ├── packages/          # framework, doctrine, security, api_platform, jwt, cors, twig
│   ├── routes.yaml
│   └── services.yaml
├── migrations/
│   └── Version20260325000001.php   # schéma initial
├── public/
│   ├── index.php
│   ├── css/
│   │   ├── app.css        # styles globaux + thèmes
│   │   ├── dashboard.css  # styles dashboard/calendrier/widgets
│   │   ├── components.css # modales, notifications, composants
│   │   └── shell.css      # header/navigation
│   └── js/app.js          # logique UI + appels API Platform (JSON-LD)
├── src/
│   ├── Controller/
│   │   └── PageController.php      # routes Twig (/, /app)
│   ├── Entity/
│   │   ├── User.php                # #[ApiResource] register + /me
│   │   ├── RunLog.php              # #[ApiResource] CRUD complet
│   │   ├── Race.php                # #[ApiResource] CRUD complet
│   │   └── PlanCheck.php          # #[ApiResource] upsert via State Processor
│   ├── EventListener/
│   │   ├── SetOwnerListener.php    # auto-assign user sur POST
│   │   └── HashPasswordListener.php # auto-hash mot de passe
│   ├── Repository/                 # UserRepository, RunLogRepository, etc.
│   └── State/
│       └── PlanCheckProcessor.php  # upsert plan_checks
├── templates/
│   ├── base/
│   │   ├── layout.html.twig
│   │   └── login.html.twig
│   ├── dashboard/
│   │   └── index.html.twig         # page dashboard principale
│   ├── log/
│   ├── plans/
│   └── courses/
├── Dockerfile
├── docker-compose.yml
└── .env
```

---

## 🚀 Déploiement web (Docker)

### 1. Préparer le serveur

```bash
git clone https://github.com/Ella-rep/running-tracker.git
cd running-tracker
cp .env.local.dist .env.local
```

Remplir **obligatoirement** dans `.env.local` :

```bash
# Générer APP_SECRET
openssl rand -hex 32

# Générer JWT_PASSPHRASE
openssl rand -hex 16
```

Puis renseigner les variables d'integration externes (obligatoires):

```bash
# OAuth Google (connexion avec Google)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
# Optionnel mais recommande: URI de callback explicite pour eviter redirect_uri_mismatch
# Exemple local:  http://localhost:8080/connect/google/check
# Exemple prod:   https://rt.lavergne.online/connect/google/check
GOOGLE_REDIRECT_URI=

# Geolocalisation IP (dashboard meteo)
GEO_KEY=

# Email sortant Symfony Mailer
MAILER_FROM=no-reply@running-dashboard.app
MAILER_DSN=smtp://${BREVO_SMTP_LOGIN}:${BREVO_SMTP_KEY}@smtp-relay.brevo.com:587?encryption=tls&auth_mode=login
CONTACT_EMAIL_TO=contact@exemple.tld

# SMTP Brevo (obligatoire)
BREVO_SMTP_LOGIN=
BREVO_SMTP_KEY=
```

Important pour Google OAuth:

- Dans Google Cloud Console, ajoutez l'URI exacte dans "Authorized redirect URIs".
- Elle doit etre strictement identique (scheme http/https, domaine, port, chemin).
- URI attendue par l'app: `/connect/google/check`.

Alertes operationnelles (admin/users):

- Le bloc "Alertes operationnelles" affiche dans `templates/admin/users.html.twig` (section autour de la ligne 92) sert a donner un etat rapide securite + activite aux admins.
- Il est alimente cote backend par `alerts` construit dans `App\Controller\AdminUserController::index`.
- Regles actuelles d'affichage:
  - `warning` si resets mot de passe >= 5 sur 24h.
  - `critical` si suppressions utilisateurs >= 3 sur 24h.
  - `warning` si aucune nouvelle seance (run log) sur 48h.
  - `warning` ou `critical` si erreurs OAuth Google detectees dans les logs sur 24h.
  - `ok` (message de stabilite) si aucune alerte metier n'est declenchee.

Parametres relies aux alertes OAuth Google:

- Les echecs OAuth Google sont journalises en niveau `error` avec le message `Google OAuth authentication failure.`
- Champs traces dans le contexte log: `route`, `message`, `oauth_error`, `oauth_error_description`
- Le rapport detaille se base sur `var/log/<APP_ENV>*.log` (fenetre 24h)
- Envoi email du rapport: `MAILER_FROM` -> `CONTACT_EMAIL_TO`
- Endpoint reserve admin (`ROLE_ADMIN`): `POST /api/admin/maintenance/gmail-errors/report`

Exemple `MAILER_DSN` avec Brevo (recommande):

```bash
MAILER_DSN=smtp://${BREVO_SMTP_LOGIN}:${BREVO_SMTP_KEY}@smtp-relay.brevo.com:587?encryption=tls&auth_mode=login
```

### 2. Déploiement Docker

Le projet se lance avec Docker Compose. Le `Dockerfile` ne dépend pas de build args spécifiques : la génération des clés JWT est exécutée au démarrage du conteneur par `docker/entrypoint.sh`.

### 3. Lancer en mode standard (recommandé)

```bash
docker compose up -d --build
```

**Au premier démarrage**, le conteneur génère les clés JWT RSA, applique les migrations, chauffe le cache, puis démarre PHP-FPM et Nginx.

### Commandes exécutées au démarrage :

```bash
# Migrations Doctrine
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Installation des assets
php bin/console assets:install public --symlink --relative --env=prod

# Cache warmup
php bin/console cache:warmup --env=prod

# Démarrage PHP-FPM (en arrière-plan)
php-fpm -D

# Attente du socket PHP-FPM
until [ -S /run/php-fpm.sock ]; do sleep 0.2; done

# Démarrage Nginx
exec nginx -g "daemon off;"
```

→ L'application est disponible sur **http://localhost:8080**
→ La doc API Swagger est sur **http://localhost:8080/api/docs**

---

## ☸️ Déploiement sans Docker Compose (Kubernetes / kubectl)

Si vous déployez l'image sans Docker Compose, il faut fournir explicitement les variables d'environnement Symfony/Doctrine/JWT.

### Variables requises

| Variable | Requis | Type recommandé | Rôle |
|---------|--------|-----------------|------|
| `APP_ENV` | Oui | ConfigMap | Environnement Symfony (`prod`, `dev`) |
| `APP_SECRET` | Oui | Secret | Secret applicatif Symfony |
| `DATABASE_HOST` | Oui | ConfigMap | Hôte PostgreSQL |
| `DATABASE_PORT` | Oui | ConfigMap | Port PostgreSQL |
| `DATABASE_NAME` | Oui | ConfigMap | Nom de la base |
| `DATABASE_USER` | Oui | Secret | Utilisateur PostgreSQL |
| `DATABASE_PASSWORD` | Oui | Secret | Mot de passe PostgreSQL |
| `DATABASE_VERSION` | Oui | ConfigMap | Version serveur PostgreSQL (ex: `15`) |
| `DEFAULT_URI` | Oui | ConfigMap | URL de base utilisée par le router Symfony |
| `JWT_SECRET_KEY` | Oui | ConfigMap | Chemin vers la clé privée JWT dans le conteneur |
| `JWT_PUBLIC_KEY` | Oui | ConfigMap | Chemin vers la clé publique JWT dans le conteneur |
| `JWT_PASSPHRASE` | Oui | Secret | Passphrase de la clé privée JWT |
| `JWT_TTL` | Oui | ConfigMap | Durée de vie du token JWT (secondes) |
| `CORS_ALLOW_ORIGIN` | Oui | ConfigMap | Regex d'origine autorisée CORS |
| `GEO_KEY` | Oui (peut etre vide) | Secret | Cle API de geolocalisation IP utilisee par les conseils meteo |
| `GOOGLE_CLIENT_ID` | Oui | Secret | OAuth Google: identifiant client |
| `GOOGLE_CLIENT_SECRET` | Oui | Secret | OAuth Google: secret client |
| `BREVO_SMTP_LOGIN` | Oui | Secret | Identifiant SMTP Brevo |
| `BREVO_SMTP_KEY` | Oui | Secret | Cle SMTP Brevo |
| `MAILER_DSN` | Oui | ConfigMap/Secret | DSN Symfony Mailer (construit avec Brevo) |
| `MAILER_FROM` | Oui | ConfigMap | Expediteur des emails |
| `CONTACT_EMAIL_TO` | Oui | ConfigMap/Secret | Destinataire interne du formulaire de contact (jamais affiche en UI) |

Variables optionnelles:

- `APP_DEBUG` (0/1)
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM`, `SMTP_TLS`, `SMTP_STARTTLS` (mode legacy msmtp)

### Exemple kubectl: ConfigMap + Secret

```bash
# Config non sensible
kubectl create configmap runtracker-config \
  --from-literal=APP_ENV=prod \
  --from-literal=DATABASE_HOST=postgres-service \
  --from-literal=DATABASE_PORT=5432 \
  --from-literal=DATABASE_NAME=running_tracker_db \
  --from-literal=DATABASE_VERSION=15 \
  --from-literal=DEFAULT_URI=https://running.example.com \
  --from-literal=JWT_SECRET_KEY=/app/config/jwt/private.pem \
  --from-literal=JWT_PUBLIC_KEY=/app/config/jwt/public.pem \
  --from-literal=JWT_TTL=604800 \
  --from-literal=CORS_ALLOW_ORIGIN='^https://running\\.example\\.com$'

# Secrets applicatifs
kubectl create secret generic runtracker-secrets \
  --from-literal=APP_SECRET='<genere_avec_openssl_rand_hex_32>' \
  --from-literal=DATABASE_USER='runner' \
  --from-literal=DATABASE_PASSWORD='<mot_de_passe_db>' \
  --from-literal=JWT_PASSPHRASE='<passphrase_jwt>' \
  --from-literal=GEO_KEY='<cle_geo>' \
  --from-literal=GOOGLE_CLIENT_ID='<google_client_id>' \
  --from-literal=GOOGLE_CLIENT_SECRET='<google_client_secret>' \
  --from-literal=BREVO_SMTP_LOGIN='<brevo_login>' \
  --from-literal=BREVO_SMTP_KEY='<brevo_smtp_key>'

# Configuration mailer obligatoire (Symfony Mailer + Brevo)
kubectl create configmap runtracker-mailer \
  --from-literal=MAILER_FROM='no-reply@running-dashboard.app' \
  --from-literal=MAILER_DSN='smtp://<BREVO_SMTP_LOGIN>:<BREVO_SMTP_KEY>@smtp-relay.brevo.com:587?encryption=tls&auth_mode=login' \
  --from-literal=CONTACT_EMAIL_TO='contact@exemple.tld'

# Clés JWT montées en fichiers
kubectl create secret generic runtracker-jwt-keys \
  --from-file=private.pem=./config/jwt/private.pem \
  --from-file=public.pem=./config/jwt/public.pem
```

### Exemple minimal de montage dans un Deployment

```yaml
envFrom:
  - configMapRef:
      name: runtracker-config
  - configMapRef:
      name: runtracker-mailer
  - secretRef:
      name: runtracker-secrets
volumeMounts:
  - name: jwt-keys
    mountPath: /app/config/jwt
    readOnly: true
volumes:
  - name: jwt-keys
    secret:
      secretName: runtracker-jwt-keys
```

### Executer un script SQL via kubectl

Depuis un fichier SQL local:

```bash
# Remplace <postgres-pod> par le pod PostgreSQL
kubectl exec -i <postgres-pod> -- psql -U runner -d postgres < ./script.sql
```

Depuis une ConfigMap (script embarque):

```bash
kubectl create configmap runtracker-sql --from-file=script.sql=./script.sql

# Extraire le SQL de la ConfigMap puis l'injecter dans psql
kubectl get configmap runtracker-sql -o jsonpath='{.data.script\.sql}' \
  | kubectl exec -i <postgres-pod> -- psql -U runner -d postgres
```

Test rapide de connectivite SQL:

```bash
kubectl exec -it <postgres-pod> -- psql -U runner -d postgres -c "SELECT now();"
```

Notes importantes:

- L'entrypoint attend PostgreSQL en se basant sur `DATABASE_HOST` / `DATABASE_PORT`.
- Si les clés JWT ne sont pas montées, l'entrypoint peut les générer dans `/app/config/jwt` (selon permissions du volume).
- En production, privilégier un Secret pour toutes les variables sensibles (`APP_SECRET`, credentials DB, `JWT_PASSPHRASE`, `GEO_KEY`, credentials Google OAuth, credentials Brevo/SMTP).

---

## 🔑 Premier compte

Va sur `http://localhost:8080` → "Créer un compte".

Ou via curl :

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username":"toi","plainPassword":"monmotdepasse"}'
```

Par defaut, un compte cree n'a pas `ROLE_ADMIN` et ne voit pas l'onglet Admin.

### Donner ROLE_ADMIN a un utilisateur

Avec Docker local:

```bash
docker exec -it runtracker_db psql -U runner -d postgres \
  -c "UPDATE users SET roles='[\"ROLE_USER\",\"ROLE_ADMIN\"]'::json WHERE username='toi';"
```

Avec Kubernetes:

```bash
# Remplace <postgres-pod> par le pod PostgreSQL
kubectl exec -it <postgres-pod> -- psql -U runner -d postgres \
  -c "UPDATE users SET roles='[\"ROLE_USER\",\"ROLE_ADMIN\"]'::json WHERE username='toi';"
```

Verification rapide:

```bash
kubectl exec -it <postgres-pod> -- psql -U runner -d postgres \
  -c "SELECT id, username, roles FROM users ORDER BY id;"
```

---


## 💾 Sauvegarde

```bash
# Dump PostgreSQL
docker exec runtracker_db pg_dump -U runner postgres > backup_$(date +%Y%m%d).sql

# Restauration
docker exec -i runtracker_db psql -U runner postgres < backup_20260325.sql
```

### Sauvegarde automatique (cron NAS)

```bash
# Tous les jours à 3h
0 3 * * * docker exec runtracker_db pg_dump -U runner postgres \
  > /volume1/backups/runtracker_$(date +\%Y\%m\%d).sql
```

---

## 🔧 Commandes utiles

```bash
# Logs en temps réel
docker compose logs -f

# Logs d'un service
docker compose logs -f app
docker compose logs -f db

# Reconstruire après modification
docker compose up -d --build

# Redémarrer l'app uniquement
docker compose restart app

# Passer une commande Symfony
docker exec runtracker_app php bin/console cache:clear
docker exec runtracker_app php bin/console doctrine:migrations:status

# Créer une nouvelle migration après modification d'une entité
docker exec runtracker_app php bin/console doctrine:migrations:diff

# Arrêter sans supprimer les données
docker compose down

# Arrêter ET supprimer toutes les données (irréversible)
docker compose down -v
```

---

## 🌐 Reverse proxy (accès HTTPS depuis l'extérieur)

### Nginx Proxy Manager (recommandé sur Synology/QNAP)

- **Type** : HTTP
- **Forward Hostname** : `localhost` (ou `127.0.0.1`)
- **Forward Port** : `8080`
- **Domaine** : `running.ton-domaine.com`
- Activer SSL Let's Encrypt

### Mettre à jour CORS après config HTTPS

Dans `.env.local` :
```bash
CORS_ALLOW_ORIGIN='^https://running\.ton-domaine\.com$'
```

Puis :
```bash
docker compose up -d
```

---

## 🛠️ Développement local

```bash
# Lancer uniquement la base de données
docker compose up -d db

# Installer les dépendances PHP
composer install

# Créer le fichier .env.local
cp .env.local.dist .env.local
# Éditer APP_SECRET, JWT_PASSPHRASE et DATABASE_URL pour pointer vers localhost:5432

# Générer les clés JWT
mkdir -p config/jwt
JWT_PASSPHRASE="$(grep '^JWT_PASSPHRASE=' .env.local | cut -d= -f2)"
openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 \
  -pass pass:"$JWT_PASSPHRASE"
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:"$JWT_PASSPHRASE"

# Appliquer les migrations
php bin/console doctrine:migrations:migrate

# Lancer le serveur de dev Symfony
symfony server:start
# ou
php -S localhost:8000 -t public/
```

La doc API est sur : **http://localhost:8000/api/docs**

---

## ⚡ Performance (frontend)

- Le dashboard utilise un chargement en 2 phases :
  - phase 1 : rendu rapide des données cœur
  - phase 2 : chargement différé des widgets lourds (métriques avancées, conseils, etc.)
- En cas de lenteur perçue, vérifier en priorité :
  - temps de réponse de `/api/dashboard/metrics`
  - taille des tables (`run_logs`, `plan_details`, `calendar_events`)
  - latence réseau entre app et base PostgreSQL

---

## 🔒 Sécurité

- Mots de passe hashés **bcrypt** (coût 12)
- Sessions **JWT RSA-4096** (expiration configurable, 7 jours par défaut)
- Chaque ressource est isolée par utilisateur (vérification `object.getUser() == user`)
- Les clés JWT sont persistées dans un volume Docker séparé (`jwt_keys`)
- En production, ne jamais exposer le port 5432 (PostgreSQL) directement

---

