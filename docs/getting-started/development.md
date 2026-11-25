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
| 4 | `queue-worker` | `ognivo-queue-dev` | Fast asynchronous job processor (`php artisan queue:work --queue=high,metrics,default --timeout=120`) |
| 5 | `queue-worker-heavy` | `ognivo-queue-dev-heavy` | Heavy asynchronous job processor for provisioning and snapshots (`php artisan queue:work --queue=heavy,default --timeout=600`) |
| 6 | `scheduler` | `ognivo-scheduler-dev` | Periodic task runner (`php artisan schedule:work`) |
| 7 | `incus-events` | `ognivo-incus-events-dev` | Hypervisor event stream listener (`php artisan incus:listen`) |
| 8 | `reverb` | `ognivo-reverb-dev` | Laravel Reverb WebSocket server on port `8080` |
| 9 | `console-proxy` | `ognivo-console-proxy-dev` | Node.js 26 WebSocket-to-Incus mTLS console bridge (`:8085`) |
| 10 | `frontend` | `ognivo-frontend-dev` | Next.js 16 dev server (`npm run dev`) on port `3000` with Turbopack |
| 11 | `webserver` | `ognivo-nginx-dev` | Unified Nginx reverse proxy on port `80` |

---

## 2. Startup Sequence

```bash
make up-dev
```

1. **Secrets & Image Build:** If `.env.development` is missing, `make` runs `scripts/gen-secrets.sh` automatically (which also creates the `backend/.env -> ../.env.development` symlink for host CLI/IDE tooling). If the `ognivo-*:dev` Docker images have not been built yet, Docker Compose builds them automatically on first run (use `make build-dev` later if you change a `Dockerfile` or `package.json`). Whenever you modify variables or rotate keys in `.env.development`, re-run `make up-dev` so Docker Compose recreates the containers with the updated environment.
2. **Database & Redis Health:** `db` (MySQL 8.4) and `redis` start first, initialize database users via [`docker/mysql-init/01-create-users.sh`](../../docker/mysql-init/01-create-users.sh), and must pass their healthchecks (`healthy`).
3. **Backend Bootstrap (`ognivo-backend-dev`):** Boots via [`backend/docker-entrypoint.sh`](../../backend/docker-entrypoint.sh):
   - Automatically runs `composer install` if `backend/vendor/autoload.php` is missing on the host bind mount (e.g., on a fresh `git clone`).
   - Runs `php artisan migrate --force` and `php artisan audit:grants --force`.
   - When `APP_ENV=local` and `RUN_SEEDERS=true`, runs `php artisan db:seed --force` before starting PHP-FPM.
4. **Workers & Real-Time Services:** `queue-worker`, `queue-worker-heavy`, `scheduler`, `incus-events`, `reverb`, and `console-proxy` start once `backend` reports `healthy`.
5. **Frontend & Ingress:** `frontend` (Next.js Turbopack) and `webserver` (Nginx) start and begin serving requests on `http://localhost`.

To manually re-run migrations or seeders, or to wipe the database volume and start fresh:

```bash
make migrate-dev   # Run migrations and apply audit grants
make seed-dev      # Re-run idempotent database seeders
make reset-dev     # Wipe database volumes and restart a clean dev stack
```

---

## 3. Seeded Demo Data

Seeded by `backend/database/seeders/DatabaseSeeder.php`. The seeder refuses to run when `APP_ENV=production` and is idempotent (`firstOrCreate`), so re-running it never overwrites records you changed.

### 3.1 Demo Accounts

| Role | Email | Password | Quota group | Effective limits |
| :--- | :--- | :--- | :--- | :--- |
| **Administrator** | `admin@ognivo.local` | `password` | *None* | Personal quota set to `∞` (unlimited) for VMs, vCPU, RAM, Disk, IPs, and Snapshots |
| **Customer** | `customer@ognivo.local` | `password` | **Standard Tier** *(Default)* | Personal quota: `3` VMs, `6` vCPU, `8 GB` RAM, `120 GB` Disk, `2` IPs, `3` Snapshots |

> [!NOTE]
> The customer's personal quota is created with its own values, which are **higher than the Standard Tier group limits** listed below (`2` VMs, `4` vCPU, `4 GB` RAM, `80 GB` Disk, `1` IP). Group limits are applied to a user when an administrator edits the group or re-assigns it. Until then the customer keeps the personal values above. New accounts created through registration receive the default group's limits.

> [!CAUTION]
> These demo accounts use publicly documented credentials and are restricted strictly to local development (`APP_ENV != production`). Never expose `make up-dev` to the public internet.

### 3.2 Quota Groups (Plans / Tiers)

| Tier name | Default | VMs | vCPU | RAM | Disk | IPs | Snapshots |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **Standard Tier** | Yes (`★`) | `2` | `4` | `4 GB` | `80 GB` | `1` | `3` |
| **Developer Tier** | No | `8` | `16` | `16 GB` | `300 GB` | `4` | `10` |
| **Performance Tier** | No | `32` | `64` | `64 GB` | `1500 GB` | `16` | `30` |

All three tiers have finite limits. Unlimited (`∞`, stored as `-1`) is only used for the seeded administrator's personal quota. You can create an unlimited group yourself under **Users & Quotas → Quota Groups & Plans**.

### 3.3 Demo Hypervisor Nodes (`FakeHypervisorDriver`)

Three active nodes using the `fake` driver, so no real Incus server is needed. Each has the three demo images below attached.

| Node identifier | Region / Location | Endpoint (placeholder) | Capacity |
| :--- | :--- | :--- | :--- |
| `eu-west-1` | Europe West (Frankfurt) | `https://eu-west-1.incus.local:8443` | `64` Cores, `256 GB` RAM, `8 TB` Storage |
| `us-east-1` | US East (N. Virginia) | `https://us-east-1.incus.local:8443` | `64` Cores, `256 GB` RAM, `8 TB` Storage |
| `bg-sofia-1` | Europe East (Sofia) | `https://bg-sofia-1.incus.local:8443` | `32` Cores, `128 GB` RAM, `4 TB` Storage |

Capacity is marked as `discovered` and synced at seed time. The endpoints are placeholders and are never contacted by the fake driver.

### 3.4 Demo OS Images

| Image | Source | Protocol | OS type |
| :--- | :--- | :--- | :--- |
| `images:ubuntu/24.04` | `https://images.linuxcontainers.org` | `simplestreams` | `linux` |
| `images:debian/12` | `https://images.linuxcontainers.org` | `simplestreams` | `linux` |
| `images:alpine/3.20` | `https://images.linuxcontainers.org` | `simplestreams` | `linux` |

All three are enabled and attached to every demo node.

### 3.5 Default Network Pool (Ranged IPAM)

- **Name:** `Default Public IPv4 Pool`
- **CIDR:** `198.51.100.0/24` (usable range: `198.51.100.1` – `198.51.100.254`)
- **Gateway:** `198.51.100.1` (dynamically excluded from allocation at runtime; not stored in `excluded_ips`)
- **DNS servers:** `1.1.1.1`, `8.8.8.8`
- **Network mode:** `routed` (`parent_interface: eth0`), IPv4, public, active
- **Cooldown:** `300` seconds before a released IP can be reused
- **Assigned to:** the first active host, which is `eu-west-1`

### 3.6 Demo Instances

Two running instances owned by `customer@ognivo.local`, both on `eu-west-1` in the `default` Incus project:

| Name | Type | vCPU | RAM | Disk | Image | IPv4 |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `dev-container-01` | LXC container | `2` | `2 GB` | `20 GB` | `images:ubuntu/24.04` | `198.51.100.10` |
| `dev-vm-01` | KVM virtual machine | `2` | `2 GB` | `25 GB` | `images:debian/12` | `198.51.100.11` |

> [!NOTE]
> Because the nodes use the `fake` driver, these instances are database records only. Lifecycle actions, snapshots and the terminal are simulated and do not touch a real hypervisor. Connect a real Incus node (see [Host Onboarding](../features/host-onboarding.md)) to manage real instances.

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