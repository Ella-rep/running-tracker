# 🏃 Running Tracker — Symfony 7 + API Platform + PostgreSQL

Application de suivi running avec :
- **Backend** : Symfony 7 + API Platform 3 (REST auto-généré, doc Swagger)
- **Auth** : JWT via LexikJWTAuthenticationBundle
- **BDD** : PostgreSQL 15 + Doctrine ORM + migrations automatiques
- **Frontend** : Twig (pages) + JS vanilla (interactions via API)
- **Déploiement** : Docker Compose (Debian + PHP 8.4-FPM + Nginx + PostgreSQL)

---

## Structure du projet

```
running-symfony/
├── bin/console
├── config/
│   ├── packages/          # framework, doctrine, security, api_platform, jwt, cors, twig
│   ├── routes.yaml
│   └── services.yaml
├── migrations/
│   └── Version20260325000001.php   # schéma initial
├── public/
│   ├── index.php
│   ├── css/app.css        # palette Forest + tous les styles
│   └── js/app.js          # logique SPA + appels API Platform (JSON-LD)
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
│   └── base/
│       ├── layout.html.twig
│       ├── login.html.twig         # page de connexion / inscription
│       └── app.html.twig           # shell SPA principal
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

### 2. Build args disponibles (Dockerfile)

Le Dockerfile supporte ces arguments de build :

- `INSTALL_REMOTE_PROJECT` (default: `false`)
- `branch_project_name` (default: `main`)
- `git_project_account` (optionnel)
- `git_project_account_secret` (optionnel)
- `project_env` (optionnel)
- `git_project_repo_url` (default: `https://github.com/Ella-rep/running-tracker.git`)

### 3. Lancer en mode standard (recommandé)

```bash
docker compose up -d --build
```

### 4. Lancer avec récupération Git au build (optionnel)

```bash
docker compose build app \
  --build-arg INSTALL_REMOTE_PROJECT=true \
  --build-arg branch_project_name=main \
  --build-arg git_project_repo_url=https://github.com/Ella-rep/running-tracker.git \
  --build-arg project_env=prod
docker compose up -d
```

Exemple si le repo nécessite une authentification :

```bash
docker compose build app \
  --build-arg INSTALL_REMOTE_PROJECT=true \
  --build-arg branch_project_name=main \
  --build-arg git_project_account=<user> \
  --build-arg git_project_account_secret=<token>
docker compose up -d
```

**Au premier démarrage**, le conteneur génère les clés JWT RSA,
applique les migrations, chauffe le cache, puis démarre PHP-FPM et Nginx.

→ L'application est disponible sur **http://localhost:8080**
→ La doc API Swagger est sur **http://localhost:8080/api/docs**

---

## 🔑 Premier compte

Va sur `http://localhost:8080` → "Créer un compte".

Ou via curl :

```bash
curl -X POST http://localhost:8080/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username":"toi","plainPassword":"monmotdepasse"}'
```

---

## 📡 API — endpoints générés par API Platform

Tous les endpoints sont documentés sur `/api/docs` (Swagger UI interactif).

| Méthode | Route | Description |
|---------|-------|-------------|
| `POST` | `/api/auth/register` | Créer un compte |
| `POST` | `/api/auth/login` | Connexion → retourne `{ token }` |
| `GET` | `/api/auth/me` | Profil courant |
| `GET` | `/api/run_logs` | Liste des sorties (triées par date desc) |
| `POST` | `/api/run_logs` | Ajouter une sortie |
| `PUT` | `/api/run_logs/{id}` | Modifier une sortie |
| `DELETE` | `/api/run_logs/{id}` | Supprimer une sortie |
| `GET` | `/api/races` | Liste des courses |
| `POST` | `/api/races` | Ajouter une course |
| `PUT` | `/api/races/{id}` | Modifier |
| `DELETE` | `/api/races/{id}` | Supprimer |
| `GET` | `/api/plan_checks` | État des coches de plan |
| `POST` | `/api/plan_checks` | Cocher/décocher (upsert) |

Tous les endpoints `/api/*` (sauf login et register) nécessitent le header :
```
Authorization: Bearer <token>
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

# Générer les clés JWT
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 \
  -pass pass:changeme
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:changeme

# Créer le fichier .env.local
cp .env.local.dist .env.local
# Éditer DATABASE_URL pour pointer vers localhost:5432

# Appliquer les migrations
php bin/console doctrine:migrations:migrate

# Lancer le serveur de dev Symfony
symfony server:start
# ou
php -S localhost:8000 -t public/
```

La doc API est sur : **http://localhost:8000/api/docs**

---

## 🔒 Sécurité

- Mots de passe hashés **bcrypt** (coût 12)
- Sessions **JWT RSA-4096** (expiration configurable, 7 jours par défaut)
- Chaque ressource est isolée par utilisateur (vérification `object.getUser() == user`)
- Les clés JWT sont persistées dans un volume Docker séparé (`jwt_keys`)
- En production, ne jamais exposer le port 5432 (PostgreSQL) directement

---

## ❓ FAQ

**Les clés JWT sont perdues après `docker compose down` ?**
Non — elles sont dans le volume `jwt_keys` qui survit à `down`. Seul `down -v` les supprime (ce qui invalide toutes les sessions).

**Comment changer le port ?**
Modifier `APP_PORT` dans `.env.local` puis `docker compose up -d`.

**Je vois "Apache2 Default Page" sur localhost, c'est normal ?**
Oui: cela signifie en général que vous ouvrez `http://localhost` (port 80 de la machine hôte),
pas le port publié par Docker Compose.

Utilisez l'URL de l'application:
- `http://localhost:8080`
- `http://localhost:8080/api/docs`

Vérification rapide:
- `docker compose ps` puis contrôler la colonne `PORTS` de `runtracker_app`
- si besoin, changer `APP_PORT` puis relancer `docker compose up -d --build`

**Comment ajouter un deuxième utilisateur ?**
Via l'interface web (bouton "Créer un compte") ou via l'API `/api/auth/register`.

**La doc Swagger est accessible sans authentification ?**
Oui, `/api/docs` est public. Les endpoints eux-mêmes requièrent un token JWT.
