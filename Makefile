# —— Misc 🛠️️ —————————————————————————————————————————————————————————————————
.DEFAULT_GOAL = help
.PHONY: # complete if needed

# —— Executables 🛠️️ —————————————————————————————————————————————————————————————————
DOCKER_COMPOSE = docker compose -f docker-compose.yaml
DOCKER_EXEC = docker exec -ti
SERVER = runtracker_app
DATABASE = runtracker_db
SERVER_EXEC = @$(DOCKER_EXEC) $(SERVER) ash -c

## —— Global 🛠️️ —————————————————————————————————————————————————————————————————
help: ## Commands list
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'

## —— Docker 🐳  ———————————————————————————————————————————————————————————————
up: ## Run project containers
	@$(DOCKER_COMPOSE) --env-file .env.docker up -d --remove-orphans

stop: ## Stop project containers
	@$(DOCKER_COMPOSE) stop

run: up ## Run Apache server
	@$(SERVER_EXEC) /run.sh

restart: stop run ## Restart project containers


ash: #up ## Run ash in server container
	@$(DOCKER_EXEC) $(SERVER) ash

load_bdd_dump: run # Load database dump file
	docker cp ./seed.sql $(DATABASE):/var/lib/postgresql
	docker cp ./seed-plans.sql $(DATABASE):/var/lib/postgresql
	@$(DOCKER_EXEC) $(DATABASE) psql --host=127.0.0.1 --port=5432 --username=runner --dbname=running_tracker_db -f /var/lib/postgresql/seed.sql
	@$(DOCKER_EXEC) $(DATABASE) psql --host=127.0.0.1 --port=5432 --username=runner --dbname=running_tracker_db -f /var/lib/postgresql/seed-plans.sql