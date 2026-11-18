.PHONY: help secrets build-dev build-prod build-all up-dev up-prod down-dev down-prod logs-dev logs-prod ps migrate migrate-dev seed seed-dev reset-dev

COMPOSE_DEV_FILES := -f docker-compose.dev.yml $(if $(wildcard docker-compose.dev.override.yml),-f docker-compose.dev.override.yml)
COMPOSE_PROD_FILES := -f docker-compose.prod.yml $(if $(wildcard docker-compose.prod.override.yml),-f docker-compose.prod.override.yml)

COMPOSE_DEV := docker compose $(COMPOSE_DEV_FILES) --env-file .env.development
COMPOSE_PROD := docker compose $(COMPOSE_PROD_FILES) --env-file .env.production

help:
	@echo "Available commands:"
	@echo "  make secrets     - Idempotently generate missing secrets and audit keys in .env.development and .env.production"
	@echo "  make build-dev   - Build development images (backend:dev, frontend:dev)"
	@echo "  make build-prod  - Build production images (backend:prod, frontend:prod)"
	@echo "  make build-all   - Build both development and production images"
	@echo "  make up-dev      - Start the development environment (hot-reload enabled)"
	@echo "  make up-prod     - Start the production environment (isolated, HTTPS)"
	@echo "  make down-dev    - Stop and remove development containers"
	@echo "  make down-prod   - Stop and remove production containers"
	@echo "  make migrate-dev - Run database migrations and apply audit grants in development"
	@echo "  make seed-dev    - Run database seeders in the development environment"
	@echo "  make reset-dev   - Stop all containers, wipe database volumes, and start fresh dev"
	@echo "  make logs-dev    - Tail logs from development containers"
	@echo "  make logs-prod   - Tail logs from production containers"
	@echo "  make ps          - List status of all project containers"

# Secrets generation target
secrets:
	@bash scripts/gen-secrets.sh

# Ensure environment files exist before running commands
.env.development:
	@if [ ! -f .env.development ]; then \
		bash scripts/gen-secrets.sh; \
	fi

.env.production:
	@if [ ! -f .env.production ]; then \
		bash scripts/gen-secrets.sh; \
	fi

# Build targets
build-dev: .env.development
	@echo "Building development images..."
	$(COMPOSE_DEV) build

build-prod: .env.production
	@echo "Building production images..."
	$(COMPOSE_PROD) build

build-all: build-dev build-prod
	@echo "All images (dev & prod) built successfully."

# Start targets
up-dev: .env.development
	@if docker ps --format '{{.Names}}' | grep -q -E '^ognivo-nginx$$'; then \
		echo "Production environment is currently running. Stopping production to avoid port conflicts..."; \
		$(MAKE) down-prod; \
	fi
	@echo "Starting development environment..."
	$(COMPOSE_DEV) up -d
	@docker restart ognivo-nginx-dev 2>/dev/null || true

up-prod: .env.production
	@if docker ps --format '{{.Names}}' | grep -q -E '^ognivo-nginx-dev$$'; then \
		echo "Development environment is currently running. Stopping dev to avoid port conflicts..."; \
		$(MAKE) down-dev; \
	fi
	@echo "Starting production environment..."
	$(COMPOSE_PROD) up -d
	@docker restart ognivo-nginx 2>/dev/null || true

# Stop targets
down-dev:
	@echo "Stopping development environment..."
	@$(COMPOSE_DEV) down --remove-orphans 2>/dev/null || true

down-prod:
	@echo "Stopping production environment..."
	@$(COMPOSE_PROD) down --remove-orphans 2>/dev/null || true

# Database migrations and seeding
migrate: migrate-dev

migrate-dev: .env.development
	@echo "Running database migrations and applying audit grants in development backend..."
	@docker exec -i ognivo-backend-dev php artisan migrate --force
	@docker exec -i ognivo-backend-dev php artisan audit:grants --force

seed: seed-dev

seed-dev: .env.development
	@echo "Running database seeders in development backend..."
	@docker exec -i ognivo-backend-dev php artisan db:seed --force

reset-dev: .env.development
	@$(MAKE) down-prod
	@$(MAKE) down-dev
	@echo "Removing dev database volumes..."
	@for vol in $$(docker volume ls -q | grep -E '(^|_)db_data(_dev)?$$'); do \
     		echo "Removing volume: $$vol"; \
     		docker volume rm "$$vol"; \
    done
	@$(MAKE) up-dev

# Logs and status
logs-dev:
	$(COMPOSE_DEV) logs -f

logs-prod:
	$(COMPOSE_PROD) logs -f

ps:
	@echo "=== Project Containers Status ==="
	@docker ps -a --filter "name=ognivo-"
