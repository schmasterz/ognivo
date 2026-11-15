# Configuration Reference

Complete reference for environment variables, network exposure, WebSocket routing, healthchecks, production safety checks, and encryption key rotation in **Ognivo**.

---

## 1. Port Allocation & Network Exposure

| Service | Dev Host Port | Prod Host Port | Protocol | Notes |
| :--- | :--- | :--- | :--- | :--- |
| `webserver` (Nginx) | `80` | `80`, `443` | HTTP / HTTPS | In prod, port `80` redirects to `443` with a `307` (except `/up`). |
| `frontend` (Next.js) | `3000` | *Internal only* | HTTP | Proxied via Nginx (`/`). |
| `reverb` (WebSockets) | `8080` | *Internal only* | WS / WSS | Proxied via Nginx. Prod routes only `^/app/` (`/app/{appKey}`), so `/apps/*` is not reachable externally. Dev proxies both `/app` and `/apps`. |
| `console-proxy` | *Internal (`8085`)* | *Internal (`8085`)* | WS / WSS | Proxied via Nginx (`/console`). |
| `db` (MySQL 8.4) | `127.0.0.1:3306` | *Internal only* | MySQL | Localhost-bound in dev; internal network only in prod. |
| `redis` (Redis 7) | *Internal (`6379`)* | *Internal (`6379`)* | Redis | Internal Docker network only. |

---

## 2. Reverb WebSocket Split Network Flow

1. **Internal Backend-to-Reverb Broadcasts:**
   PHP-FPM and `queue-worker` broadcast events over the internal Docker bridge network using `REVERB_HOST=reverb`, `REVERB_PORT=8080`, and `REVERB_SCHEME=http`.
2. **External Browser Connections:**
   Browsers connect via `wss://<host>/app/{appKey}` (or `ws://<host>/app/{appKey}` over HTTP), which Nginx proxies to `http://reverb:8080`. The client picks the port in this order: the page's own port (when set and not `3000`), then `NEXT_PUBLIC_REVERB_PORT` (HTTPS only), then `443` / `80`. See the note on `NEXT_PUBLIC_REVERB_PORT` in section 3.
3. **Allowed Origins Whitelist (`REVERB_ALLOWED_ORIGINS`):**
   Defaults to `APP_URL`. In production, supply a comma-separated list of allowed origins (e.g., `https://panel.example.com`). A wildcard `*` is rejected by `app:check-production-config` when `APP_ENV=production` (see section 5); the development example uses `*`.

---

## 3. Environment Variables Reference

### Creating and updating env files

Initialize `.env.development` and `.env.production` with `make secrets` (runs `scripts/gen-secrets.sh`). The script:

- Creates each file from `.env.development.example` / `.env.production.example` if it does not exist.
- Generates `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `NEXT_PUBLIC_REVERB_APP_KEY`, the `AUDIT_*` / `DB_MIGRATION_*` credentials, `AUDIT_HMAC_KEY`, and `AUDIT_PSEUDONYM_SALT` when they are missing, empty, or `CHANGE_ME`. Existing values are preserved.
- Does **not** add other variables that were introduced in the example files later. When the example files gain new variables (for example `PROVISION_*`), copy them into your existing `.env.development` / `.env.production` by hand.

> [!IMPORTANT]
> **Container Recreate Required After `.env` Changes:**
> Docker Compose injects variables from `.env.development` and `.env.production` (`env_file:`) into container processes only when containers are created. Editing `.env.development` or `.env.production` on the host — or rotating keys (`APP_KEY`, `AUDIT_HMAC_KEY`, `REVERB_*`) — does **not** update running containers automatically. Always run `make up-dev` (development) or `make up-prod` (production) after modifying environment variables so Docker Compose recreates the affected containers with the new values, and run `make build-prod` if changing compile-time `NEXT_PUBLIC_*` variables in production.
>
> **Host CLI Symlink (`backend/.env`):** `make secrets` automatically creates a git-ignored symlink `backend/.env -> ../.env.development` so host-side `php artisan` commands and IDE plugins read the centralized root `.env.development` file without duplicating configuration.

### Core Application
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `APP_NAME` | `"Ognivo"` | Application name shown in UI and emails |
| `APP_ENV` | `production` | Runtime environment (`local` or `production`). `production` enables `app:check-production-config` on container start |
| `APP_KEY` | *(Random base64 32B)* | Primary AES-256-CBC key encrypting mTLS keys, host tokens, and the stored SMTP password |
| `APP_PREVIOUS_KEYS` | *(Empty)* | Comma-separated previous `APP_KEY` values (full value including the `base64:` prefix) used during key rotation |
| `APP_DEBUG` | `false` | Must be `false` in production (enforced on boot) |
| `APP_URL` | `https://localhost` | Canonical public URL of the panel |
| `APP_LOCALE` | `en` | Default locale (`en` or `bg`) for code running outside an HTTP request: queue workers (e.g. queued emails) and console commands. API responses choose the locale per request (`?lang=`, `X-Locale`, then `Accept-Language`) and fall back to `en`, independent of this value |
| `APP_FALLBACK_LOCALE` | `en` | Laravel fallback locale for missing translation keys |
| `LOG_LEVEL` | `debug` | Minimum log level. Use `warning` or `info` in production |
| `TRUSTED_PROXIES` | *(RFC 1918 ranges + `127.0.0.1`)* | Reverse proxies trusted for `X-Forwarded-*`: comma-separated IPs/CIDRs or `*`. The default covers Docker bridge networks |

### Database, Cache & Queues
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `DB_CONNECTION` | `mysql` | Primary database driver |
| `DB_HOST` / `DB_PORT` | `db` / `3306` | MySQL service hostname and port |
| `DB_DATABASE` | `ognivo` | Database name (compose maps it to `MYSQL_DATABASE`) |
| `DB_USERNAME` | `ognivo_user` | Application database user |
| `DB_PASSWORD` | *(Random generated)* | Application DB password |
| `DB_ROOT_PASSWORD` | *(Random generated)* | Root DB password (compose maps it to `MYSQL_ROOT_PASSWORD`) |
| `REDIS_CLIENT` | `predis` | PHP Redis client |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379` | Redis service hostname and port |
| `REDIS_PASSWORD` | *(Random generated)* | Redis password. Required in production; the stack refuses to start without one |
| `REDIS_PREFIX` | `ognivo-database-` | Key prefix for Redis database namespace separation (also used by `console-proxy`) |
| `QUEUE_CONNECTION` | `redis` | Asynchronous queue driver |
| `CACHE_STORE` | `redis` | Cache store driver |

### Sessions & API Tokens
| Variable | Default | Description |
| :--- | :--- | :--- |
| `SESSION_DRIVER` | `redis` | Session storage driver |
| `SESSION_LIFETIME` | `120` | Session lifetime in minutes |
| `SESSION_SECURE_COOKIE` | *(unset)* | Set to `true` when served over HTTPS (production) |
| `SESSION_SAME_SITE` | `lax` | `SameSite` attribute of the session cookie |
| `SESSION_DOMAIN` | *(unset)* | Cookie domain |
| `SANCTUM_EXPIRATION` | `1440` | API token lifetime in minutes (24 hours) |
| `SANCTUM_STATEFUL_DOMAINS` | *(Sanctum default)* | Domains treated as stateful SPA origins (see `config/sanctum.php`) |
| `SANCTUM_TOKEN_PREFIX` | *(empty)* | Optional token prefix |

### Broadcasting & Frontend
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `BROADCAST_CONNECTION` | `reverb` | Event broadcasting driver |
| `REVERB_APP_ID` | `ognivo` | Reverb application ID |
| `REVERB_APP_KEY` | *(Random generated)* | Reverb public key (must match `NEXT_PUBLIC_REVERB_APP_KEY`) |
| `REVERB_APP_SECRET` | *(Random generated)* | Reverb signing secret |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | `reverb` / `8080` / `http` | Internal address used by the backend and queue workers to broadcast |
| `REVERB_ALLOWED_ORIGINS` | `https://localhost` | Allowed WebSocket Origin headers (no `*` in prod). Defaults to `APP_URL` when unset |
| `NEXT_PUBLIC_API_URL` | `/api` | Base URL for frontend API requests (baked in at build) |
| `NEXT_PUBLIC_REVERB_APP_KEY` | *(Matches backend)* | Baked into the Next.js client bundle during build; the build fails if it is empty |
| `NEXT_PUBLIC_REVERB_PORT` | `443` | Browser WebSocket port override for HTTPS pages (see note below) |
| `NEXT_PUBLIC_ENABLE_FAKE_DRIVER` | *(unset)* | Development only: set to `true` to show the `fake` driver option in the host forms |

> [!NOTE]
> **`NEXT_PUBLIC_REVERB_PORT` in production builds:** `docker-compose.prod.yml` passes it as a build argument, but `frontend/Dockerfile.prod` only declares `NEXT_PUBLIC_API_URL` and `NEXT_PUBLIC_REVERB_APP_KEY`, so the value is not inlined into the bundle. In practice the browser uses the page's own port or `443`. If you need a custom port, add `ARG` / `ENV NEXT_PUBLIC_REVERB_PORT` to `Dockerfile.prod`.

### Startup Entrypoint Flags
| Variable | Prod | Dev | Description |
| :--- | :--- | :--- | :--- |
| `RUN_MIGRATIONS` | `true` | `true` | Runs `php artisan migrate --force` on container startup |
| `RUN_SEEDERS` | `false` | `true` | Seeders run only when `APP_ENV` is not `production`. Must be `false` in production |

### Provisioning & Capacity
Read by `HostSelector` (placement) and `HostCapacityGuard` (hard capacity limits). A host accepts a new VM only if `committed + requested <= total * overcommit - reserve` holds for CPU, RAM, and disk. Committed usage sums all VMs whose status is not `failed` or `deleted`, including those still provisioning.

| Variable | Default | Description |
| :--- | :--- | :--- |
| `PROVISION_CPU_OVERCOMMIT` | `1.0` | CPU overcommit multiplier (`1.0` = none) |
| `PROVISION_RAM_OVERCOMMIT` | `1.0` | RAM overcommit multiplier. Keep at `1.0` unless swap/zram is monitored |
| `PROVISION_DISK_OVERCOMMIT` | `1.0` | Disk overcommit multiplier. Raise only with thin provisioning and pool-usage alerts |
| `PROVISION_HOST_CPU_RESERVE_CORES` | `0` | CPU cores reserved for the host OS |
| `PROVISION_HOST_RAM_RESERVE_MB` | `1024` | RAM reserved for the host OS and Incus |
| `PROVISION_HOST_DISK_RESERVE_GB` | `0` | Disk reserved for snapshots, images, and logs |
| `PROVISION_STORAGE_POOL` | `default` | Incus storage pool used for instances and disk capacity |
| `PROVISION_NETWORK_WAIT_SECONDS` | `120` | How long the provisioning job waits for the instance network |
| `PROVISION_POLL_INTERVAL_SECONDS` | `10` | Polling interval of the provisioning job |
| `PROVISION_JOB_DEADLINE_SECONDS` | `900` | Overall provisioning job deadline |

A compute node is offered for placement only when it is active, healthy (`status=ok`), has known capacity, has the requested image, has an active IP pool with a free address, and fits the requested resources. Among eligible nodes the least loaded one is chosen.

### Rate Limiting
| Variable | Default | Description |
| :--- | :--- | :--- |
| `VM_ACTION_RATE_LIMIT` | `10` | Allowed VM actions per decay window |
| `VM_ACTION_RATE_LIMIT_DECAY_MINUTES` | `1` | Length of the rate-limit window in minutes |

### Mail
SMTP is configured at runtime from the admin panel (system settings, `smtp_settings`; the password is stored encrypted with `APP_KEY`). That configuration is applied on every application boot and overrides the variables below. The `MAIL_*` variables are only the fallback used while no SMTP host is configured in the panel.

| Variable | Default | Description |
| :--- | :--- | :--- |
| `MAIL_MAILER` | `log` | Fallback mailer. `log` writes messages to the log instead of delivering them |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Fallback From address |
| `MAIL_FROM_NAME` | `${APP_NAME}` | Fallback From name |

Mail is used for email verification, password reset, the hourly `audit:verify` failure alert to active admins, and the SMTP test message in the admin panel.

### Hypervisor Connectivity
| Variable | Default (Prod) | Description |
| :--- | :--- | :--- |
| `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS` | `false` | When `false`, fetching the TLS peer certificate of a compute node (done when adding a node) rejects private-range and loopback addresses as an SSRF mitigation. Set to `true` when Incus compute nodes are deployed on private RFC 1918 / LAN or loopback addresses |
| `ALLOW_FAKE_DRIVER` | `false` | Permits creating hosts with the `fake` driver outside `local` / `testing`. Placement still excludes `fake` hosts outside those environments, so leave it unset in production |

*(For `AUDIT_*` and `DB_MIGRATION_*` variables, see [security-and-audit.md](../architecture/security-and-audit.md#3-audit-environment-variables)).*

---

## 4. Container Healthchecks

In production these services define native Docker healthchecks:
- **Backend (`php-fpm`):** FastCGI ping (`cgi-fcgi` against `127.0.0.1:9000/ping`)
- **Webserver (`nginx`):** HTTPS probe (`curl -k -f`) to `https://127.0.0.1/up`
- **Frontend (`nextjs`):** HTTP probe (`wget --spider`) to `http://127.0.0.1:3000/`
- **Reverb:** HTTP probe to `http://127.0.0.1:8080/`
- **Console proxy:** HTTP probe (`wget --spider`) to `http://127.0.0.1:8085/health`
- **MySQL & Redis:** Native CLI pings with credentials (`mysqladmin ping`, `redis-cli -a "$REDIS_PASSWORD" ping`)

The queue worker, scheduler, Incus events listener, and cert-init containers do not define a healthcheck.

---

## 5. Production Safety Enforcement (`app:check-production-config`)

When `APP_ENV=production`, `backend/docker-entrypoint.sh` runs `php artisan app:check-production-config` before migrations. The container immediately exits with code `1` if any of the following are detected:
1. `APP_KEY` empty, `CHANGE_ME`, or the known compromised example key.
2. `DB_PASSWORD` set to `secret_password` or `CHANGE_ME`.
3. `DB_ROOT_PASSWORD` set to `secret_password` or `CHANGE_ME`.
4. `REVERB_APP_SECRET` set to `reverb_secret_key` or `CHANGE_ME`.
5. `REVERB_ALLOWED_ORIGINS` containing wildcard `*`.
6. `APP_DEBUG=true` (or `1`).
7. `RUN_SEEDERS=true` (or `1`).
8. `REDIS_PASSWORD` empty, `null`, `secret_password`, or `CHANGE_ME`.
9. `REVERB_APP_KEY` empty, `null`, `CHANGE_ME`, or `secret_password`.
10. `AUDIT_DB_PASSWORD` empty, `null`, `secret_password`, or `CHANGE_ME`.
11. `AUDIT_MAINT_DB_PASSWORD` empty, `null`, `secret_password`, or `CHANGE_ME`.
12. `DB_MIGRATION_PASSWORD` empty, `null`, `secret_password`, or `CHANGE_ME`.
13. `AUDIT_HMAC_KEY` empty, `null`, `CHANGE_ME`, or `secret_password`.
14. `AUDIT_PSEUDONYM_SALT` empty, `null`, `CHANGE_ME`, or `secret_password`.

Run `make secrets` to generate valid values for all secret entries above.

---

## 6. `APP_KEY` Rotation Procedure

`APP_KEY` encrypts `panel_certificates.private_key`, `hosts.token`, and the SMTP password stored in `system_settings` (`smtp_settings`). `app:reencrypt-secrets` re-encrypts every model attribute cast as `encrypted` plus the SMTP password. Because Docker Compose injects `.env.production` (or `.env.development`) variables at container creation time, rotating `APP_KEY` requires recreating the containers (`make up-prod` / `make up-dev`) before and after re-encrypting database secrets:

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
   # development container: ognivo-backend-dev
   ```
5. **Verify hypervisor connectivity** in the Admin UI (**Verify connection** on hosts) and send the SMTP test email from the admin panel.
6. **Clear `APP_PREVIOUS_KEYS=""`** in `.env.production` and run `make up-prod` again so the old key is removed from the container environment.

`AUDIT_HMAC_KEY` is rotated separately through `AUDIT_HMAC_KEY_ID` and `AUDIT_PREVIOUS_HMAC_KEYS` (JSON or comma-separated `id:key` pairs); see [security-and-audit.md](../architecture/security-and-audit.md#3-audit-environment-variables).