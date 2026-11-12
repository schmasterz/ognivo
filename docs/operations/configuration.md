# Configuration Reference

Complete reference for environment variables, network exposure, WebSocket routing, healthchecks, and encryption key rotation in **Ognivo**.

---

## 1. Port Allocation & Network Exposure

| Service | Dev Host Port | Prod Host Port | Protocol | Notes |
| :--- | :--- | :--- | :--- | :--- |
| `webserver` (Nginx) | `80` | `80`, `443` | HTTP / HTTPS | In prod, port `80` redirects to `443` (except `/up`). |
| `frontend` (Next.js) | `3000` | *Internal only* | HTTP | Proxied via Nginx (`/`). |
| `reverb` (WebSockets) | `8080` | *Internal only* | WS / WSS | Proxied via Nginx (`/app/{appKey}`). `/apps/*` is blocked externally. |
| `console-proxy` | *Internal (`8085`)* | *Internal (`8085`)* | WS / WSS | Proxied via Nginx (`/console`). |
| `db` (MySQL 8.4) | `127.0.0.1:3306` | *Internal only* | MySQL | Localhost-bound in dev; internal network only in prod. |
| `redis` (Redis 7) | *Internal (`6379`)* | *Internal (`6379`)* | Redis | Internal Docker network only. |

---

## 2. Reverb WebSocket Split Network Flow

1. **Internal Backend-to-Reverb Broadcasts:**
   PHP-FPM and `queue-worker` broadcast events over the internal Docker bridge network using `REVERB_HOST=reverb`, `REVERB_PORT=8080`, and `REVERB_SCHEME=http`.
2. **External Browser Connections:**
   Browsers connect via `wss://<host>/app/{appKey}` on port `443` (or `ws://localhost:8080` in dev), which Nginx proxies to `http://reverb:8080`.
3. **Allowed Origins Whitelist (`REVERB_ALLOWED_ORIGINS`):**
   Defaults to `APP_URL`. In production, supply a comma-separated list of allowed origins (e.g., `https://panel.example.com`). Wildcard `*` is rejected by the startup safety check.

---

## 3. Environment Variables Reference

Initialize `.env.development` and `.env.production` via `make secrets`.

> [!IMPORTANT]
> **Container Recreate Required After `.env` Changes:**
> Docker Compose injects variables from `.env.development` and `.env.production` (`env_file:`) into container processes only when containers are created. Editing `.env.development` or `.env.production` on the host — or rotating keys (`APP_KEY`, `AUDIT_HMAC_KEY`, `REVERB_*`) — does **not** update running containers automatically. Always run `make up-dev` (development) or `make up-prod` (production) after modifying environment variables so Docker Compose recreates the affected containers with the new values, and run `make build-prod` if changing compile-time `NEXT_PUBLIC_*` variables in production.
>
> **Host CLI Symlink (`backend/.env`):** `make secrets` automatically creates a git-ignored symlink `backend/.env -> ../.env.development` so host-side `php artisan` commands and IDE plugins read the centralized root `.env.development` file without duplicating configuration.

### Core Application
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `"Ognivo"` | Application name shown in UI and emails |
| `APP_ENV` | `production` | Runtime environment (`local` or `production`) |
| `APP_KEY` | *(Random base64 32B)* | Primary AES-256-CBC key encrypting mTLS keys and host tokens |
| `APP_PREVIOUS_KEYS` | *(Empty)* | Comma-separated previous `APP_KEY` values used during key rotation |
| `APP_DEBUG` | `false` | Must be `false` in production (enforced on boot) |
| `APP_URL` | `https://localhost` | Canonical public URL of the panel |

### Database, Cache & Queues
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `DB_CONNECTION` | `mysql` | Primary database driver |
| `DB_HOST` / `DB_PORT` | `db` / `3306` | MySQL service hostname and port |
| `DB_DATABASE` | `ognivo` | Database name |
| `DB_USERNAME` | `ognivo_user` | Application database user |
| `DB_PASSWORD` | *(Random generated)* | Application DB password |
| `DB_ROOT_PASSWORD` | *(Random generated)* | Root DB password |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | Redis service hostname and port |
| `QUEUE_CONNECTION` | `redis` | Asynchronous queue driver |
| `CACHE_STORE` | `redis` | Cache store driver |
| `SESSION_DRIVER` | `database` | Session storage driver |

### Broadcasting & Frontend
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `BROADCAST_CONNECTION` | `reverb` | Event broadcasting driver |
| `REVERB_APP_ID` | `ognivo` | Reverb application ID |
| `REVERB_APP_KEY` | *(Random generated)* | Reverb public key (must match `NEXT_PUBLIC_REVERB_APP_KEY`) |
| `REVERB_APP_SECRET` | *(Random generated)* | Reverb signing secret |
| `REVERB_ALLOWED_ORIGINS` | `https://localhost` | Allowed WebSocket Origin headers (no `*` in prod) |
| `NEXT_PUBLIC_API_URL` | `/api` | Base URL for frontend API requests |
| `NEXT_PUBLIC_REVERB_APP_KEY` | *(Matches backend)* | Baked into Next.js client bundle during build |
| `NEXT_PUBLIC_REVERB_PORT` | `443` | Browser WebSocket port in production |

### Startup Entrypoint Flags
| Variable | Prod | Dev | Description |
| :--- | :--- | :--- | :--- |
| `RUN_MIGRATIONS` | `true` | `true` | Runs `php artisan migrate --force` on container startup |
| `RUN_SEEDERS` | `false` | `true` | Must be `false` in production |

*(For `AUDIT_*` and `DB_MIGRATION_*` variables, see [security-and-audit.md](../architecture/security-and-audit.md#3-audit-environment-variables)).*

---

## 4. Container Healthchecks

All core services define native Docker healthchecks:
- **Backend (`php-fpm`):** FastCGI ping (`cgi-fcgi` against `127.0.0.1:9000/ping`)
- **Webserver (`nginx`):** HTTPS probe to `https://127.0.0.1/up`
- **Frontend (`nextjs`):** HTTP probe to `http://127.0.0.1:3000/`
- **Reverb:** HTTP probe to `http://127.0.0.1:8080/`
- **MySQL & Redis:** Native CLI pings (`mysqladmin ping`, `redis-cli ping`)

---

## 5. Production Safety Enforcement (`app:check-production-config`)

When `APP_ENV=production`, `backend/docker-entrypoint.sh` runs `php artisan app:check-production-config`. The container immediately exits with code `1` if any of the following are detected:
1. Empty `APP_KEY`, `CHANGE_ME`, or a known compromised example key.
2. `DB_PASSWORD` or `DB_ROOT_PASSWORD` set to `secret_password` or `CHANGE_ME`.
3. `REVERB_APP_SECRET` set to `reverb_secret_key` or `CHANGE_ME`.
4. `REVERB_ALLOWED_ORIGINS` containing wildcard `*`.
5. `APP_DEBUG=true` or `RUN_SEEDERS=true`.

---

## 6. `APP_KEY` Rotation Procedure

`APP_KEY` encrypts `panel_certificates.private_key` and `hosts.token`. Because Docker Compose injects `.env.production` (or `.env.development`) variables at container creation time, rotating `APP_KEY` requires recreating the containers (`make up-prod` / `make up-dev`) before and after re-encrypting database secrets:

1. **Move the current key to `APP_PREVIOUS_KEYS`** in `.env.production` (or `.env.development`):
   ```ini
   APP_PREVIOUS_KEYS="base64:PreviousKeyHere..."
   ```
2. **Generate and set a new `APP_KEY`:**
   ```bash
   openssl rand -base64 32
   ```
   ```ini
   APP_KEY="base64:NewGeneratedKeyHere..."
   ```
3. **Apply the updated environment variables to the running containers:**
   ```bash
   make up-prod   # or: make up-dev
   ```
4. **Re-encrypt all database secrets with the new key:**
   ```bash
   docker exec -it ognivo-backend php artisan app:reencrypt-secrets
   ```
5. **Verify hypervisor connectivity** in the Admin UI (**Verify connection** on hosts).
6. **Clear `APP_PREVIOUS_KEYS=""`** in `.env.production` and run `make up-prod` again so the old key is removed from the container environment.
