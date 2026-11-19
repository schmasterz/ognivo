# Commands Reference

Reference of all **Makefile**, **Docker Compose**, **Laravel Artisan**, and container inspection commands.

> [!NOTE]
> The Makefile and `scripts/gen-secrets.sh` need **Bash** and **OpenSSL** on the host. On Windows, run everything inside WSL2.

---

## 1. Makefile Targets (Recommended)

The root [`Makefile`](../../Makefile) manages secret initialization, prevents dev/prod port collisions, and wraps common container workflows.

| Command | Description |
| :--- | :--- |
| `make help` | Print summary of all available Makefile targets |
| `make secrets` | Idempotently generate missing secrets and audit keys in `.env.development` and `.env.production`. Existing values are preserved |
| `make build-dev` | Build the development images (`ognivo-backend:dev`, `ognivo-frontend:dev`, `ognivo-console-proxy:dev`) |
| `make build-prod` | Build the self-contained production images (`ognivo-backend:prod`, `ognivo-frontend:prod`, `ognivo-console-proxy:prod`) |
| `make build-all` | Build both development and production images |
| `make up-dev` | Start the development stack in detached mode (auto-stops production if it is running) |
| `make up-prod` | Start the production stack in detached mode over HTTPS (auto-stops development if it is running) |
| `make down-dev` | Stop and remove development containers (volumes are kept) |
| `make down-prod` | Stop and remove production containers (volumes are kept) |
| `make migrate-dev` (alias `make migrate`) | Run `php artisan migrate --force` and `php artisan audit:grants --force` in the development backend |
| `make seed-dev` (alias `make seed`) | Run the idempotent database seeders (`php artisan db:seed --force`) in the development backend |
| `make reset-dev` | Stop all containers, delete the **development** database volume (`ognivo_db_data_dev`), and start a fresh dev stack. Production data volumes are never touched |
| `make logs-dev` | Stream real-time logs from all development containers |
| `make logs-prod` | Stream real-time logs from all production containers |
| `make ps` | List status and health of all `ognivo-*` containers |

> [!CAUTION]
> `make reset-dev` permanently deletes the development database, including any data you created in the demo environment. Check what it will remove first with `docker volume ls -q | grep -E '_db_data_dev$'`.

Notes:

- `make up-dev`, `make up-prod`, `make build-*`, `make migrate-dev`, `make seed-dev` and `make reset-dev` create the missing `.env.*` file(s) they need automatically.
- Starting one environment stops the other, so they never fight over ports. `make up-dev` and `make up-prod` also restart the Nginx container so it picks up fresh upstream addresses.
- **Override files.** If `docker-compose.dev.override.yml` or `docker-compose.prod.override.yml` exists in the repository root, the Makefile adds it automatically (`-f` flag) to every dev or prod command. Use it for local changes such as custom certificate mounts (see [Production Deployment](production.md#method-b-mount-lets-encrypt-directories-via-docker-composeprodoverrideyml)) or restricting dev ports to `127.0.0.1`. Override files are not committed.
- After editing `.env.development` or `.env.production`, run `make up-dev` or `make up-prod` so containers are recreated with the new values. See [Configuration](configuration.md#3-environment-variables-reference).

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

> [!NOTE]
> These commands do not include override files or the "stop the other environment" step that the Makefile performs. If you use an override file, add `-f docker-compose.<env>.override.yml` after the base `-f`. Never run the dev and prod stacks at the same time, because both publish ports `80`.

---

## 3. Laravel Artisan & Maintenance Commands

Run inside the backend container: `ognivo-backend` (production) or `ognivo-backend-dev` (development). The examples below use the production name; replace it with `ognivo-backend-dev` in development.

```bash
# Open an interactive shell inside the backend container
docker exec -it ognivo-backend-dev bash

# General form
docker exec -it ognivo-backend php artisan <command> [arguments] [options]
```

### 3.1 Setup & Safety

| Command | Description |
| :--- | :--- |
| `admin:create {email} [--name=] [--password=]` | Create an administrator with the `admin` role and unlimited quotas. A 16-character password is generated and printed when `--password` is omitted. Required in production, because seeders are disabled |
| `app:check-production-config` | Validate that no unsafe defaults or placeholder secrets are in use. Runs automatically on production container start and exits with code `1` on failure |
| `app:reencrypt-secrets` | Re-encrypt all encrypted database attributes and the SMTP password with the current `APP_KEY`. Used during [key rotation](configuration.md#6-app_key-rotation-procedure) |
| `migrate --force` | Run database migrations (done automatically when `RUN_MIGRATIONS=true`) |
| `optimize:clear`, `optimize` | Clear and rebuild Laravel caches (production) |

```bash
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin"
docker exec -it ognivo-backend php artisan admin:create admin@yourdomain.com --name="Super Admin" --password="YourStrongPasswordHere"
docker exec -it ognivo-backend php artisan app:check-production-config
docker exec -it ognivo-backend php artisan app:reencrypt-secrets
docker exec -it ognivo-backend php artisan optimize:clear && docker exec -it ognivo-backend php artisan optimize
```

### 3.2 Hypervisors (Incus / LXD)

| Command | Description |
| :--- | :--- |
| `incus:generate-cert [--days=365] [--force]` | Generate or rotate the panel's self-signed mTLS client certificate. `--force` rotates even when an active certificate exists |
| `incus:listen [host] [--once]` | Listen to Incus lifecycle events over WebSocket and sync VM states. Runs continuously in the `incus-events` container. `host` is a host ID or name. `--once` runs a single pass over active hosts and exits |
| `vm:reconcile [host]` | Compare VMs in the database with the Incus nodes and record drift. `host` (ID or name) limits it to one node |
| `vm:sweep-stale-provisioning [--max-age-seconds=]` | Fail and compensate VMs stuck in `provisioning` past the job deadline. `--max-age-seconds` overrides the cutoff |
| `hosts:sync-resources [host]` | Sync CPU, RAM and storage capacity from the hypervisors for hosts with `discovered` or `unknown` capacity (never overwrites capacity you set manually) |
| `hosts:backfill-pins [--force]` | Compute and store `server_public_key_pin` (SPKI hash) for existing hosts from their stored server certificate. `--force` overwrites existing pins |
| `hypervisor:cleanup-cert-keys` | Remove leftover `panel_cert_*.key` files from storage and temporary directories |
| `images:verify-catalog [--skip-disabled] [--id=]` | Verify allowlist catalog images against their remote servers (simplestreams, Incus, LXD) and report reachability or missing aliases |

```bash
docker exec -it ognivo-backend php artisan incus:generate-cert --force
docker exec -it ognivo-backend php artisan vm:reconcile eu-west-1
docker exec -it ognivo-backend php artisan hosts:sync-resources eu-west-1
docker exec -it ognivo-backend php artisan hosts:backfill-pins
docker exec -it ognivo-backend php artisan images:verify-catalog
docker exec -it ognivo-backend php artisan images:verify-catalog --skip-disabled
docker exec -it ognivo-backend php artisan images:verify-catalog --id=1
```

### 3.3 Audit Log

| Command | Description |
| :--- | :--- |
| `audit:grants [--force]` | Idempotently configure table-level MySQL grants for the audit users and enforce strict permissions. `--force` skips the confirmation prompt |
| `audit:seal` | Seal eligible audit rows into chained HMAC blocks |
| `audit:verify [--last=N]` | Verify seals, row hashes and HMAC signatures. `--last=N` checks only the N most recent seals |
| `audit:prune` | Archive and delete audit logs older than `AUDIT_RETENTION_DAYS` and scrub attempted-login emails after `AUDIT_ATTEMPTED_EMAIL_DAYS` |
| `audit:pseudonymise {userId}` | Scrub personal data and pseudonymise audit records of a deleted user, without breaking seal verification |

```bash
docker exec -it ognivo-backend php artisan audit:grants --force
docker exec -it ognivo-backend php artisan audit:seal
docker exec -it ognivo-backend php artisan audit:verify
docker exec -it ognivo-backend php artisan audit:verify --last=10
docker exec -it ognivo-backend php artisan audit:prune
docker exec -it ognivo-backend php artisan audit:pseudonymise 42
```

See [Security & Audit](../architecture/security-and-audit.md) for what each step does.

### 3.4 Scheduled Tasks

The `scheduler` container runs `php artisan schedule:work` and triggers these tasks automatically. You normally never need to run them by hand.

| Task | Frequency | Notes |
| :--- | :--- | :--- |
| `vm:reconcile` | Every 5 minutes | Drift detection |
| `vm:sweep-stale-provisioning` | Every 5 minutes | No overlapping runs |
| IP cooldown cleanup (`ipam:prune-cooling`) | Every minute | Returns expired `cooling` IPs to the pool; the only mechanism that deletes expired cooling IPs (read endpoints do not) |
| `audit:seal` | Every minute | No overlapping runs |
| `audit:verify` | Hourly | Emails active admins when verification fails |
| `hosts:sync-resources` | Hourly | No overlapping runs |
| `audit:prune` | Daily | |
| Panel certificate expiry warning (`WarnExpiringPanelCertificateJob`) | Daily | Warns before the panel mTLS certificate expires |
| Image catalog health (`images:verify-catalog`) | Daily | Warns if remote images are retired, unreachable, or missing |

### 3.5 Background Daemons

These run as separate containers, started by Compose. They are listed for reference and are not started by hand.

| Container (prod / dev) | Command |
| :--- | :--- |
| `ognivo-queue` / `ognivo-queue-dev` | `php artisan queue:work --verbose --tries=3 --timeout=600 --max-time=3600` |
| `ognivo-scheduler` / `ognivo-scheduler-dev` | `php artisan schedule:work` |
| `ognivo-incus-events` / `ognivo-incus-events-dev` | `php artisan incus:listen` |
| `ognivo-reverb` / `ognivo-reverb-dev` | `php artisan reverb:start --host=0.0.0.0 --port=8080` |

---

## 4. Database & Redis CLI Inspection

```bash
# Connect to MySQL CLI (Dev / Prod)
docker exec -it ognivo-db-dev mysql -u ognivo_user -p ognivo
docker exec -it ognivo-db mysql -u ognivo_user -p ognivo

# Check Redis connectivity (Dev / Prod)
docker exec -it ognivo-redis-dev redis-cli ping
docker exec -it ognivo-redis sh -c 'redis-cli -a "$REDIS_PASSWORD" ping'
```

> [!NOTE]
> In production Redis requires a password, so a bare `redis-cli ping` returns `NOAUTH`. The command above runs inside the container through `sh -c`, so `$REDIS_PASSWORD` is read from the container's environment, not from your host shell. The development Redis has no password.

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
| **TLS bootstrap (one-shot)** | `ognivo-cert-init` | *(not used)* |