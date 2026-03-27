# 🏃 Running Tracker — Symfony 7 + API Platform + PostgreSQL

Application de suivi running avec :
- **Backend** : Symfony 7 + API Platform 3 (REST auto-généré, doc Swagger)
- **Auth** : JWT via LexikJWTAuthenticationBundle
- **BDD** : PostgreSQL 16 + Doctrine ORM + migrations automatiques
- **Frontend** : Twig (pages) + JS vanilla (interactions via API)
- **Déploiement** : Docker Compose (PHP-FPM 8.2 + Nginx + PostgreSQL)

---

## Structure du projet

```
running-symfony/
├── bin/console
├── config/
│   ├── packages/          # framework, doctrine, security, api_platform, jwt, cors, twig
│   ├── routes.yaml
│   └── services.yaml
├── docker/
│   ├── entrypoint.sh      # JWT keygen + migrations + cache warmup au démarrage
│   ├── nginx.conf
│   └── supervisord.conf
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

## 🚀 Déploiement sur NAS (3 commandes)

### 1. Copier le projet

```bash
# Via SSH ou gestionnaire de fichiers du NAS
scp -r running-symfony/ user@ip-nas:/volume1/docker/running-symfony
# ou rsync
rsync -avz running-symfony/ user@ip-nas:/volume1/docker/running-symfony/
```

### 2. Configurer les variables d'environnement

```bash
cd /volume1/docker/running-symfony
cp .env.local.dist .env.local
nano .env.local
```

Remplir **obligatoirement** :

```bash
# Générer APP_SECRET
openssl rand -hex 32

# Générer JWT_PASSPHRASE
openssl rand -hex 16
```

### 3. Lancer

```bash
docker compose up -d --build
```

**Premier démarrage** : l'entrypoint génère automatiquement les clés JWT RSA,
attend que PostgreSQL soit prêt, applique les migrations, puis chauffe le cache.

→ L'application est disponible sur **http://ip-de-ton-nas:8080**
→ La doc API Swagger est sur **http://ip-de-ton-nas:8080/api/docs**

---

## 🔑 Premier compte

Va sur `http://ip-nas:8080` → "Créer un compte".

Ou via curl :

```bash
curl -X POST http://ip-nas:8080/api/users \
  -H "Content-Type: application/json" \
  -d '{"username":"toi","plainPassword":"monmotdepasse"}'
```

---

## 📡 API — endpoints générés par API Platform

Tous les endpoints sont documentés sur `/api/docs` (Swagger UI interactif).

| Méthode | Route | Description |
|---------|-------|-------------|
| `POST` | `/api/users` | Créer un compte |
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
docker exec runtracker_db pg_dump -U runner runtracker > backup_$(date +%Y%m%d).sql

# Restauration
docker exec -i runtracker_db psql -U runner runtracker < backup_20260325.sql
```

### Sauvegarde automatique (cron NAS)

```bash
# Tous les jours à 3h
0 3 * * * docker exec runtracker_db pg_dump -U runner runtracker \
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

**Comment ajouter un deuxième utilisateur ?**
Via l'interface web (bouton "Créer un compte") ou via l'API `/api/users`.

**La doc Swagger est accessible sans authentification ?**
Oui, `/api/docs` est public. Les endpoints eux-mêmes requièrent un token JWT.
