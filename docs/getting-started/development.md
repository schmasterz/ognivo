# Development Environment

How the Ognivo **development environment** is structured and what demo data it includes. For initial setup, see the [Quickstart Guide](quickstart.md).

---

## 1. Service Topology

All components run inside an isolated Docker bridge network (`app-network-dev`). Backend (`./backend`) and frontend (`./frontend`) sources are bind-mounted from the host for **live hot reloading**.

| # | Service | Container Name | Description |
| :--- | :--- | :--- | :--- |
| 1 | `db` | `ognivo-db-dev` | MySQL 8.4 LTS, exposed on `127.0.0.1:3306` for local inspection |
| 2 | `redis` | `ognivo-redis-dev` | Redis 7 Alpine for cache, queues, locks, and console tickets |
| 3 | `backend` | `ognivo-backend-dev` | PHP 8.5-FPM Alpine running the Laravel 13 API |
| 4 | `queue-worker` | `ognivo-queue-dev` | Asynchronous job processor (`php artisan queue:work`) |
| 5 | `scheduler` | `ognivo-scheduler-dev` | Periodic task runner (`php artisan schedule:work`) |
| 6 | `incus-events` | `ognivo-incus-events-dev` | Hypervisor event stream listener (`php artisan incus:listen`) |
| 7 | `reverb` | `ognivo-reverb-dev` | Laravel Reverb WebSocket server on port `8080` |
| 8 | `console-proxy` | `ognivo-console-proxy-dev` | Node.js 22 WebSocket-to-Incus mTLS console bridge (`:8085`) |
| 9 | `frontend` | `ognivo-frontend-dev` | Next.js 16 dev server (`npm run dev`) on port `3000` with Turbopack |
| 10 | `webserver` | `ognivo-nginx-dev` | Unified Nginx reverse proxy on port `80` |

---

## 2. Startup Sequence

```bash
make up-dev
```

1. **Secrets & Image Build:** If `.env.development` is missing, `make` runs `scripts/gen-secrets.sh` automatically. If the `ognivo-*:dev` Docker images have not been built yet, Docker Compose builds them automatically on first run (use `make build-dev` later if you change a `Dockerfile` or `package.json`).
2. **Database & Redis Health:** `db` (MySQL 8.4) and `redis` start first, initialize database users via [`docker/mysql-init/01-create-users.sh`](../../docker/mysql-init/01-create-users.sh), and must pass their healthchecks (`healthy`).
3. **Backend Bootstrap (`ognivo-backend-dev`):** Boots via [`backend/docker-entrypoint.sh`](../../backend/docker-entrypoint.sh):
   - Automatically runs `composer install` if `backend/vendor/autoload.php` is missing on the host bind mount (e.g., on a fresh `git clone`).
   - Runs `php artisan migrate --force` and `php artisan audit:grants --force`.
   - When `APP_ENV=local` and `RUN_SEEDERS=true`, runs `php artisan db:seed --force` before starting PHP-FPM.
4. **Workers & Real-Time Services:** `queue-worker`, `scheduler`, `incus-events`, `reverb`, and `console-proxy` start once `backend` reports `healthy`.
5. **Frontend & Ingress:** `frontend` (Next.js Turbopack) and `webserver` (Nginx) start and begin serving requests on `http://localhost`.

To manually re-run migrations or seeders, or to wipe the database volume and start fresh:

```bash
make migrate-dev   # Run migrations and apply audit grants
make seed-dev      # Re-run idempotent database seeders
make reset-dev     # Wipe database volumes and restart a clean dev stack
```

---

## 3. Seeded Demo Data

### 3.1 Demo Accounts

| Role | Email | Password | Assigned Quota Group | Resource Limits |
| :--- | :--- | :--- | :--- | :--- |
| **Administrator** | `admin@ognivo.local` | `password` | **Enterprise Unlimited** | `∞` Unlimited across VMs, vCPU, RAM, Disk, IPs, and Snapshots |
| **Customer** | `customer@ognivo.local` | `password` | **Standard Tier** *(Default)* | `3` VMs, `6` vCPU, `8 GB` RAM, `120 GB` Disk, `2` IPs, `3` Snapshots |

> [!CAUTION]
> These demo accounts use publicly documented credentials and are restricted strictly to local development (`APP_ENV != production`). Never expose `make up-dev` to the public internet.

### 3.2 Quota Groups (Plans / Tiers)

| Tier Name | Default | Limits (VMs / vCPU / RAM / Disk / IPs / Snapshots) |
| :--- | :--- | :--- |
| **Standard Tier** | Yes (`★`) | `3` VMs / `6` Cores / `8 GB` RAM / `120 GB` Disk / `2` IPs / `3` Snapshots |
| **Developer Tier** | No | `10` VMs / `24` Cores / `32 GB` RAM / `500 GB` Disk / `5` IPs / `10` Snapshots |
| **Enterprise Unlimited** | No | `∞` VMs / `∞` Cores / `∞` RAM / `∞` Disk / `∞` IPs / `∞` Snapshots |

### 3.3 Demo Hypervisor Nodes (`FakeHypervisorDriver`)

| Node Identifier | Region / Location | Capacity |
| :--- | :--- | :--- |
| `eu-west-1` | Europe West, Frankfurt | `64` Cores, `256 GB` RAM, `8 TB` Storage |
| `us-east-1` | US East, N. Virginia | `64` Cores, `256 GB` RAM, `8 TB` Storage |
| `bg-sofia-1` | Europe East, Sofia | `32` Cores, `128 GB` RAM, `4 TB` Storage |
| `node-1-development` | Local Development Node | `32` Cores, `64 GB` RAM, `2 TB` Storage |

### 3.4 Default Network Pool (Ranged IPAM)

- **Name:** `Default Public IPv4 Pool`
- **CIDR:** `192.168.100.0/24` (usable range: `192.168.100.2` – `192.168.100.254`)
- **Gateway:** `192.168.100.1` (excluded from allocation)
- **Network Mode:** `routed` (`parent_interface: eth0`), assigned to `eu-west-1`

---

## 4. Local Ports & Endpoints

| Service | Local URL / Address | Purpose |
| :--- | :--- | :--- |
| **Unified Web Panel (Nginx)** | `http://localhost` | Main entry point for UI, API, WebSockets, and Console Proxy |
| **Next.js Dev Server** | `http://localhost:3000` | Direct frontend access with HMR |
| **Backend REST API** | `http://localhost/api` | Laravel API routes |
| **OpenAPI / Swagger UI** | `http://localhost/docs/api` | Interactive API documentation (`http://localhost/docs/api.json`) |
| **Health Check** | `http://localhost/up` | Returns `200 OK` when healthy |
| **Reverb WebSockets** | `ws://localhost:8080` *(or `/app/{appKey}`)* | Real-time UI event broadcasting |
| **MySQL Database** | `127.0.0.1:3306` | Database `ognivo`, user `ognivo_user` (password in `.env.development`) |
