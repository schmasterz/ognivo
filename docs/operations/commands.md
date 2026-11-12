# Commands Reference

Comprehensive reference of all **Makefile**, **Docker Compose**, **Laravel Artisan**, and container inspection commands.

---

## 1. Makefile Targets (Recommended)

The root [`Makefile`](../../Makefile) manages secret initialization, prevents dev/prod port collisions, and wraps common container workflows.

| Command | Description |
| :--- | :--- |
| `make help` | Print summary of all available Makefile targets |
| `make secrets` | Idempotently generate missing secrets and audit keys in `.env.development` and `.env.production` |
| `make build-dev` | Build development images (`ognivo-backend:dev`, `ognivo-frontend:dev`) |
| `make build-prod` | Build self-contained production images (`ognivo-backend:prod`, `ognivo-frontend:prod`) |
| `make build-all` | Build both development and production images |
| `make up-dev` | Start development stack in detached mode (auto-stops prod if running) |
| `make up-prod` | Start production stack in detached mode over HTTPS (auto-stops dev if running) |
| `make down-dev` | Stop and remove development containers |
| `make down-prod` | Stop and remove production containers |
| `make migrate-dev` | Run `php artisan migrate --force` and `php artisan audit:grants --force` in dev |
| `make seed-dev` | Run idempotent database seeders (`php artisan db:seed --force`) in dev |
| `make reset-dev` | Stop all containers, delete database volumes, and start a fresh dev stack |
| `make logs-dev` | Stream real-time logs from all development containers |
| `make logs-prod` | Stream real-time logs from all production containers |
| `make ps` | List status and health of all `ognivo-*` containers |

---

## 2. Native Docker Compose Commands

If running `docker compose` directly without `make`:

### Development
```bash
docker compose -f docker-compose.dev.yml --env-file .env.development build
docker compose -f docker-compose.dev.yml --env-file .env.development up -d
docker compose -f docker-compose.dev.yml --env-file .env.development down
```

### Production
```bash
docker compose -f docker-compose.prod.yml --env-file .env.production build
docker compose -f docker-compose.prod.yml --env-file .env.production up -d
docker compose -f docker-compose.prod.yml --env-file .env.production down
```

---

## 3. Laravel Artisan & Maintenance Commands

Run inside `ognivo-backend` (production) or `ognivo-backend-dev` (development):

```bash
# Open interactive shell inside backend container
docker exec -it ognivo-backend-dev bash

# Create an administrator account (Production)
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"

# Validate production configuration safety
docker exec -it ognivo-backend php artisan app:check-production-config

# Re-encrypt database secrets during APP_KEY rotation
docker exec -it ognivo-backend php artisan app:reencrypt-secrets

# Generate or rotate the panel mTLS X.509 certificate
docker exec -it ognivo-backend php artisan incus:generate-cert --force

# Audit subsystem: apply grants, seal logs, verify HMAC hash chain, prune old logs
docker exec -it ognivo-backend php artisan audit:grants --force
docker exec -it ognivo-backend php artisan audit:seal
docker exec -it ognivo-backend php artisan audit:verify
docker exec -it ognivo-backend php artisan audit:prune

# Clear and rebuild Laravel caches (Production)
docker exec -it ognivo-backend php artisan optimize:clear
docker exec -it ognivo-backend php artisan optimize
```

---

## 4. Database & Redis CLI Inspection

```bash
# Connect to MySQL CLI (Dev / Prod)
docker exec -it ognivo-db-dev mysql -u ognivo_user -p ognivo
docker exec -it ognivo-db mysql -u ognivo_user -p ognivo

# Check Redis connectivity (Dev / Prod)
docker exec -it ognivo-redis-dev redis-cli ping
docker exec -it ognivo-redis redis-cli ping
```

---

## 5. Container Names Reference

| Service | Production Container | Development Container |
| :--- | :--- | :--- |
| **Nginx Reverse Proxy** | `ognivo-nginx` | `ognivo-nginx-dev` |
| **Backend API (PHP-FPM)** | `ognivo-backend` | `ognivo-backend-dev` |
| **Frontend (Next.js)** | `ognivo-frontend` | `ognivo-frontend-dev` |
| **Database (MySQL 8.4)** | `ognivo-db` | `ognivo-db-dev` |
| **Cache & Broker (Redis 7)** | `ognivo-redis` | `ognivo-redis-dev` |
| **WebSockets (Reverb)** | `ognivo-reverb` | `ognivo-reverb-dev` |
| **Queue Worker** | `ognivo-queue` | `ognivo-queue-dev` |
| **Task Scheduler** | `ognivo-scheduler` | `ognivo-scheduler-dev` |
| **Incus Events Listener** | `ognivo-incus-events` | `ognivo-incus-events-dev` |
| **Console Proxy** | `ognivo-console-proxy` | `ognivo-console-proxy-dev` |
