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

> [!WARNING]
> In the development stack only MySQL is bound to `127.0.0.1`. Nginx (`80`), Next.js (`3000`) and Reverb (`8080`) are published on **all** host interfaces, and the seeded demo accounts use the password `password`. Run `make up-dev` only on a machine that is not reachable from untrusted networks.

---

## 2. Reverb WebSocket Split Network Flow

1. **Internal Backend-to-Reverb Broadcasts:**
   PHP-FPM and `queue-worker` broadcast events over the internal Docker bridge network using `REVERB_HOST=reverb`, `REVERB_PORT=8080`, and `REVERB_SCHEME=http`.
2. **External Browser Connections:**
   Browsers connect via `wss://<host>/app/{appKey}` (or `ws://<host>/app/{appKey}` over HTTP), which Nginx proxies to `http://reverb:8080`. The client picks the port in this order: the page's own port (when set and not `3000`), then `NEXT_PUBLIC_REVERB_PORT` (HTTPS only), then `443` / `80`. See the runtime configuration note in section 3.
3. **Allowed Origins Whitelist (`REVERB_ALLOWED_ORIGINS`):**
   Defaults to `APP_URL`. In production, supply a comma-separated list of allowed origins (e.g., `https://panel.example.com`). A wildcard `*` is rejected by `app:check-production-config` when `APP_ENV=production` (see section 5); the development example uses `*`.

---

## 3. Environment Variables Reference

### Creating and updating env files

Initialize `.env.development` and `.env.production` with `make secrets` (runs `scripts/gen-secrets.sh`). The script:

- Creates each file from `.env.development.example` / `.env.production.example` if it does not exist.
- Generates `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `NEXT_PUBLIC_REVERB_APP_KEY`, the `AUDIT_*` / `DB_MIGRATION_*` credentials, `AUDIT_HMAC_KEY`, and `AUDIT_PSEUDONYM_SALT` when they are missing, empty, or `CHANGE_ME`. Existing values are preserved.
- Does **not** add other variables that were introduced in the example files later. When the example files gain new variables (for example `PROVISION_*`, `TERMINAL_*` or `SANCTUM_STATEFUL_DOMAINS`), copy them into your existing `.env.development` / `.env.production` by hand.

> [!IMPORTANT]
> **Container Recreate Required After `.env` Changes:**
> Docker Compose injects variables from `.env.development` and `.env.production` (`env_file:`) into container processes only when containers are created. Editing `.env.development` or `.env.production` on the host — or rotating keys (`APP_KEY`, `AUDIT_HMAC_KEY`, `REVERB_*`) — does **not** update running containers automatically. Always run `make up-dev` (development) or `make up-prod` (production) after modifying environment variables so Docker Compose recreates the affected containers with the new values (or `docker compose restart frontend` when restarting the frontend process with updated container environment variables). Frontend configuration is runtime-driven — Node.js reads container environment variables upon request and feeds `<ConfigProvider>` and `GET /api/v1/config` — so `make build-prod` is **not** required when changing `NEXT_PUBLIC_*` variables.
>
> **Host CLI Symlink (`backend/.env`):** `make secrets` automatically creates a git-ignored symlink `backend/.env -> ../.env.development` so host-side `php artisan` commands and IDE plugins read the centralized root `.env.development` file without duplicating configuration.

### How to read the tables

Each table shows two defaults, because they often differ:

- **Code default**: the value the application uses when the variable is not set at all (taken from `backend/config/*.php`, `docker-compose.*.yml` or the entrypoint script).
- **In `.env.production.example`**: the value that a fresh production install gets after `make secrets`. `CHANGE_ME` entries are replaced with generated secrets.

The development example (`.env.development.example`) differs from the production one in a few values; those are listed in [Development vs production examples](#development-vs-production-examples).

### Core Application
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `APP_NAME` | `Ognivo` | `"Ognivo"` | Application name shown in UI and emails |
| `APP_ENV` | `production` | `production` | Runtime environment (`local` or `production`). `production` enables `app:check-production-config` on container start |
| `APP_KEY` | *(none)* | generated | Primary AES-256-CBC key encrypting mTLS keys, host tokens, and the stored SMTP password |
| `APP_PREVIOUS_KEYS` | *(none)* | commented out | Comma-separated previous `APP_KEY` values (full value including the `base64:` prefix) used during key rotation. Must stay set until `php artisan app:reencrypt-secrets --status` reports `0` secrets on a previous key and exits `0` |
| `APP_DEBUG` | `false` | `false` | Must be `false` in production (enforced on boot) |
| `APP_URL` | `http://localhost` | `https://panel.example.com` | Canonical public URL of the panel. Also the default for `REVERB_ALLOWED_ORIGINS` |
| `APP_LOCALE` | `en` | commented out (`en`) | Default locale (`en` or `bg`) for code running outside an HTTP request: queue workers (e.g. queued emails) and console commands. API responses choose the locale per request (`?lang=`, `X-Locale`, then `Accept-Language`) and fall back to `en`, independent of this value |
| `APP_FALLBACK_LOCALE` | `en` | commented out (`en`) | Laravel fallback locale for missing translation keys |
| `LOG_LEVEL` | `debug` | `warning` | Minimum log level. Use `warning` or `info` in production |
| `TRUSTED_PROXIES` | RFC 1918 ranges + `127.0.0.1` | commented out | Reverse proxies trusted for `X-Forwarded-*`: comma-separated IPs/CIDRs or `*`. The default covers Docker bridge networks |

### Database, Cache & Queues
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `DB_CONNECTION` | `sqlite` | `mysql` | Primary database driver. **Must be `mysql`**: the code default is `sqlite` |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | `db` / `3306` | MySQL service hostname and port |
| `DB_DATABASE` | `laravel` | `ognivo` | Database name (compose maps it to `MYSQL_DATABASE`) |
| `DB_USERNAME` | `root` | `ognivo_user` | Application database user |
| `DB_PASSWORD` | *(empty)* | generated | Application DB password |
| `DB_ROOT_PASSWORD` | `secret_password` (compose fallback) | generated | Root DB password (compose maps it to `MYSQL_ROOT_PASSWORD`). The placeholder fallback is rejected by the production check |
| `REDIS_CLIENT` | `phpredis` | `predis` | PHP Redis client. The example files use `predis` |
| `REDIS_HOST` / `REDIS_PORT` | `127.0.0.1` / `6379` | `redis` / `6379` | Redis service hostname and port |
| `REDIS_PASSWORD` | *(none)* | generated | Redis password. Required in production; the stack refuses to start without one |
| `REDIS_PREFIX` | `<APP_NAME slug>-database-` | `ognivo-database-` | Key prefix for Redis namespace separation. **`console-proxy` must use the same prefix** to find terminal tickets (compose passes `REDIS_PREFIX` to it) |
| `QUEUE_CONNECTION` | `redis` | `redis` | Asynchronous queue driver |
| `CACHE_STORE` | `redis` | `redis` | Cache store driver |

**Queue routing topology:** Two dedicated workers (`queue-worker` consuming `high,metrics,default` with a `120s` timeout, and `queue-worker-heavy` consuming `heavy,default` with a `600s` timeout) prevent long-running hypervisor operations from blocking interactive VM actions or telemetry:

| Queue | Worker(s) | Timeout | Jobs |
| :--- | :--- | :--- | :--- |
| `high` | `queue-worker` | `120s` | User-initiated VM actions (`ExecuteVmActionJob`, low latency) |
| `metrics` | `queue-worker` | `15s` | Scheduled host telemetry (`CollectHostMetricsJob`) |
| `default` | `queue-worker`, `queue-worker-heavy` | — | Webhook delivery, metrics cleanup, and panel certificate alerts (`DeliverWebhookJob`, `PurgeVmMetricsJob`, `WarnExpiringPanelCertificateJob`) |
| `heavy` | `queue-worker-heavy` | `600s` | Long-running instance provisioning, reinstalls, snapshots, and tenant deletions (`ProvisionVmJob`, `ReinstallVmJob`, `CreateSnapshotJob`, `RestoreSnapshotJob`, `DeleteSnapshotJob`, `SuspendUserWorkloadsJob`, `DeleteUserWorkloadsJob`) |

### Sessions & API Tokens
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `SESSION_COOKIE` | `ognivo_session` | `__Host-ognivo_session` | Session cookie name. Production requires the `__Host-` prefix, which enforces HTTPS (`SESSION_SECURE_COOKIE=true`), `Path=/`, and an unset `SESSION_DOMAIN` |
| `SESSION_DRIVER` | `redis` | `redis` | Session storage driver |
| `SESSION_LIFETIME` | `120` | `120` | Idle session lifetime in minutes |
| `SESSION_ABSOLUTE_LIFETIME` | `1440` | `1440` | Hard cap on browser session lifetime in minutes since login (24 hours) |
| `SESSION_SECURE_COOKIE` | *(unset)* | `true` | Send the session cookie over HTTPS only. Set to `false` for plain-HTTP development |
| `SESSION_SAME_SITE` | `strict` | `strict` | `SameSite` attribute of the session cookie (must be `strict` in production) |
| `SESSION_DOMAIN` | *(unset)* | not in file | Cookie domain. Must remain empty/unset in production when using `__Host-` cookies |
| `SANCTUM_EXPIRATION` | `1440` | `1440` | Token lifetime in minutes (24 hours). No longer affects browser sessions (Bearer tokens are disabled; kept for a future token auth mechanism) |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1` plus the host from `APP_URL` | `panel.example.com` | Hostnames (without scheme, `host[:port]`) treated as stateful SPA origins. Required in production and should match `APP_URL`; otherwise localhost and loopback origins stay trusted |
| `SANCTUM_TOKEN_PREFIX` | *(empty)* | not in file | Optional prefix for newly issued API tokens |

### Broadcasting & Frontend
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `BROADCAST_CONNECTION` | `null` | `reverb` | Event broadcasting driver. Must be `reverb` for live updates |
| `REVERB_APP_ID` | *(none)* | `ognivo` | Reverb application ID |
| `REVERB_APP_KEY` | *(none)* | generated | Reverb public key (must match `NEXT_PUBLIC_REVERB_APP_KEY`) |
| `REVERB_APP_SECRET` | *(none)* | generated | Reverb signing secret |
| `REVERB_HOST` | *(none)* | `reverb` | Internal Reverb hostname used by the backend and queue workers to broadcast |
| `REVERB_PORT` | `443` | `8080` | Internal Reverb port |
| `REVERB_SCHEME` | `https` | `http` | Internal Reverb scheme. The code default is `https`, but the internal Docker link is plain `http`, so keep the example values |
| `REVERB_ALLOWED_ORIGINS` | `APP_URL` | `https://panel.example.com` | Allowed WebSocket `Origin` headers, comma-separated (no `*` in prod) |
| `NEXT_PUBLIC_API_URL` | `/api/v1` | `/api/v1` | Base URL for frontend API requests (read at runtime) |
| `NEXT_PUBLIC_REVERB_APP_KEY` | *(none)* | generated, equals `REVERB_APP_KEY` | Reverb public key read by the frontend runtime configuration (must match `REVERB_APP_KEY`) |
| `NEXT_PUBLIC_REVERB_PORT` | `443` | `443` | Browser WebSocket port override for HTTPS pages (read at runtime) |

> [!NOTE]
> **Runtime Frontend Configuration:** Frontend configuration is runtime-driven. The Next.js Node.js server reads `NEXT_PUBLIC_*` container environment variables upon request and feeds `<ConfigProvider>` and `GET /api/v1/config`. They are **not** baked into the frontend Docker image at build time, so changing variables in `.env` only requires restarting or recreating the container (`make up-prod` / `make up-dev`, or `docker compose restart frontend`), without running `make build-prod`.

### Startup Entrypoint Flags
| Variable | Code default | Prod example | Dev example | Description |
| :--- | :--- | :--- | :--- | :--- |
| `RUN_MIGRATIONS` | `false` | `true` | `true` | Runs `php artisan migrate --force` on container startup |
| `RUN_SEEDERS` | `true` when `APP_ENV` is not `production`, otherwise never | `false` | `true` | Seeders run only when `APP_ENV` is not `production`, so an unset value seeds demo data in development. Must be `false` in production (enforced on boot) |

### Provisioning & Capacity
Read by `HostSelector` (placement) and `HostCapacityGuard` (hard capacity limits). Overcommit multipliers (`cpu_overcommit`, `ram_overcommit`, `disk_overcommit`, default `1.0x`) are configured **per compute node** in the Compute Nodes UI or API rather than via global environment variables. A node accepts a new instance only if both `committed + requested <= (total * overcommit) - reserve` and `requested <= total - reserve` (single-instance physical hardware cap) hold for CPU, RAM, and disk. Committed usage sums all VMs whose status is not `failed` or `deleted`, including those still provisioning.

| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `PROVISION_HOST_CPU_RESERVE_CORES` | `0` | `1` | CPU cores reserved for the host OS |
| `PROVISION_HOST_RAM_RESERVE_MB` | `1024` | `2048` | RAM reserved for the host OS and Incus |
| `PROVISION_HOST_DISK_RESERVE_GB` | `0` | `20` | Disk reserved for snapshots, images, and logs |
| `PROVISION_STORAGE_POOL` | `default` | `default` | Incus storage pool used for instances and disk capacity |
| `PROVISION_NETWORK_WAIT_SECONDS` | `120` | `120` | How long the provisioning job waits for the instance network |
| `PROVISION_POLL_INTERVAL_SECONDS` | `10` | `10` | Polling interval of the provisioning job |
| `PROVISION_JOB_DEADLINE_SECONDS` | `900` | `900` | Overall provisioning job deadline |

A compute node is offered for placement only when it is active, healthy (`status=ok`), has known capacity, has the requested image, has an active IP pool with a free address, and fits the requested resources. Among eligible nodes the least loaded one is chosen.

### Rate Limiting
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `VM_ACTION_RATE_LIMIT` | `10` | `10` | Allowed VM actions (start, stop, reboot, ...) per user per decay window. Administrators are not limited |
| `VM_ACTION_RATE_LIMIT_DECAY_MINUTES` | `1` | `1` | Length of the rate-limit window in minutes |

Other rate limits (login, registration, password reset, terminal tickets and boot log) are fixed in code and not configurable through `.env`.

### Mail
SMTP is configured at runtime from the admin panel (system settings, `smtp_settings`; the password is stored encrypted with `APP_KEY`). That configuration is applied on every application boot and overrides the variables below. The `MAIL_*` variables are only the fallback used while no SMTP host is configured in the panel.

| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `MAIL_MAILER` | `log` | `log` | Fallback mailer. `log` writes messages to the log instead of delivering them |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | `noreply@example.com` | Fallback From address |
| `MAIL_FROM_NAME` | `APP_NAME` | `${APP_NAME}` | Fallback From name |

Mail is used for email verification, password reset, the hourly `audit:verify` failure alert to active admins, and the SMTP test message in the admin panel.

### Hypervisor Connectivity
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS` | `false` | `false` | When `false`, fetching the TLS peer certificate of a compute node (done when adding a node) rejects private-range and loopback addresses as an SSRF mitigation. **Set to `true` when Incus compute nodes are on private RFC 1918 / LAN or loopback addresses**, which is the common case. The development example sets it to `true` |
| `ALLOW_FAKE_DRIVER` | `false` | `false` | Enables the emulated `fake` hypervisor driver in the backend (permits creating hosts with the `fake` driver outside `local` / `testing`), enables emulated terminal sessions in `console-proxy`, and reflects into `features.fake_driver` via `GET /api/v1/config` for the frontend UI. Placement still excludes `fake` hosts outside `local` / `testing`. Keep `false` in production (enforced by `app:check-production-config`) |

The panel can only manage a node that listens on the network. On the Incus host, run once:

```bash
incus config set core.https_address <node-ip>:8443
```

See [Host Onboarding & mTLS](../features/host-onboarding.md) for the full connection procedure.

### Interactive Terminal
Read from `config/hypervisor.php` by the backend. Each value is copied into the ticket of a new terminal session, so it applies to that session regardless of the `console-proxy`'s own defaults. Details: [Interactive Web Terminal](../features/console.md).

| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `TERMINAL_TICKET_TTL_SECONDS` | `60` | `60` | Lifetime of the single-use WebSocket ticket in seconds (minimum `10`) |
| `TERMINAL_IDLE_TIMEOUT_SECONDS` | `900` | `900` | A terminal with no traffic for this long is closed (minimum `30`) |
| `TERMINAL_MAX_EXEC_SESSIONS` | `5` | `5` | Concurrent `exec` shells per instance (minimum `1`) |
| `TERMINAL_MAX_CONSOLE_SESSIONS` | `1` | `1` | Concurrent serial console sessions per instance (minimum `1`) |
| `TERMINAL_MAX_MESSAGE_BYTES` | `65536` | `65536` | Largest accepted WebSocket message in bytes (minimum `1024`) |
| `CONSOLE_ALLOWED_ORIGINS` | `http://localhost` in non-prod; fails closed in prod | `https://panel.example.com` | Comma-separated exact WebSocket `Origin` allowlist for `console-proxy` (no wildcard `*`). Compose falls back to `APP_URL` in production if unset |

> [!NOTE]
> The `console-proxy` container does not read `.env.production` directly. Compose passes it only `PORT`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`, `REDIS_PREFIX`, `APP_KEY`, `APP_PREVIOUS_KEYS`, `NODE_ENV`, `ALLOW_FAKE_DRIVER`, and `CONSOLE_ALLOWED_ORIGINS`. Its own defaults for `MAX_MESSAGE_BYTES` and `IDLE_TIMEOUT_SECONDS` (`65536` / `900`) are used only when a ticket does not carry the value, which the backend always provides. To change them, edit the `console-proxy` service in the Compose file.

### 3.11 Instance Metrics & Telemetry
Read from `backend/config/metrics.php`. Details: [Instance Metrics](../features/metrics.md).

| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `METRICS_ENABLED` | `true` | `true` | Master feature flag for instance metrics scraping (`GET /1.0/metrics`), the query API (`503` `metrics_disabled` when `false`), and the UI Metrics tab. Local `metrics:rollup` and `metrics:prune` tasks still run when disabled |
| `METRICS_INTERVAL_SECONDS` | `60` | `60` | Scrape interval in seconds between raw collection cycles (allowed range `30..300`) |
| `METRICS_REQUEST_TIMEOUT_SECONDS` | `10` | `10` | Per-node hypervisor HTTP scrape timeout in seconds (maximum `10`) |
| `METRICS_RETENTION_RAW_HOURS` | `12` | `12` | Retention period in hours for raw samples (`vm_metric_samples`; minimum `6`) |
| `METRICS_RETENTION_5M_DAYS` | `8` | `8` | Retention period in days for 5-minute rollups (`vm_metric_rollups_5m`; minimum `8`) |
| `METRICS_RETENTION_1H_DAYS` | `35` | `35` | Retention period in days for 1-hour rollups (`vm_metric_rollups_1h`; minimum `35`) |
| `METRICS_AUDIT_WINDOW_SECONDS` | `900` | `900` | Deduplication window in seconds (`15 minutes`) in Redis per `(admin, instance)` for `vm.metrics.viewed` cross-tenant audit events |
| `METRICS_RATE_LIMIT_PER_MINUTE` | `60` | `60` | Maximum instance metrics API queries per user per minute |

### OpenAPI Documentation (Scramble)
Read from `backend/config/scramble.php`.

| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `SCRAMBLE_ALLOW_PUBLIC` | `false` | `false` | Controls whether interactive OpenAPI documentation (`/docs/api` and `/docs/api.json`) is publicly accessible outside `local`/`testing`. Default `false` makes it admin-only in production (requires an authenticated user with the `admin` role) |
| `SCRAMBLE_TRY_IT_ENABLED` | `false` | `false` | Enables or disables the interactive "Try it out" requests in Stoplight Elements. Disabled (`false`) because the docs UI cannot attach the `X-XSRF-TOKEN` header on state-changing requests |

> [!NOTE]
> **Production Content-Security-Policy:** The interactive Scramble UI uses Stoplight Elements web-components loaded from `https://unpkg.com`. `docker/nginx.prod.conf` whitelists `https://unpkg.com` in the `script-src` and `style-src` directives of the `Content-Security-Policy` header so those UI assets can load.

### Server & Concurrency (PHP-FPM & Nginx)
| Variable | Code default | In `.env.production.example` | Description |
| :--- | :--- | :--- | :--- |
| `PHP_FPM_MAX_CHILDREN` | `30` | `30` | Maximum child worker processes spawned by PHP-FPM (`pm.max_children`). Each child processes up to 1000 requests before recycling |

### Development vs production examples

| Variable | `.env.development.example` | `.env.production.example` |
| :--- | :--- | :--- |
| `APP_ENV` / `APP_DEBUG` / `APP_URL` | `local` / `true` / `http://localhost` | `production` / `false` / `https://panel.example.com` |
| `APP_NAME` | `"Ognivo (Dev)"` | `"Ognivo"` |
| `LOG_LEVEL` | `debug` | `warning` |
| `REDIS_PASSWORD` | `null` (replaced by a generated value by `make secrets`) | generated |
| `REVERB_ALLOWED_ORIGINS` | `*` | `https://panel.example.com` (replace with your domain) |
| `NEXT_PUBLIC_REVERB_PORT` | `8080` | `443` |
| `RUN_SEEDERS` | `true` | `false` |
| `SESSION_COOKIE` | `ognivo_session` | `__Host-ognivo_session` |
| `SESSION_SECURE_COOKIE` | `false` | `true` |
| `SESSION_SAME_SITE` | `strict` | `strict` |
| `SESSION_ABSOLUTE_LIFETIME` | `1440` | `1440` |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1` | `panel.example.com` (replace with your domain) |
| `CONSOLE_ALLOWED_ORIGINS` | `http://localhost` | `https://panel.example.com` (replace with your domain) |
| `SCRAMBLE_ALLOW_PUBLIC` | `true` | `false` |
| `SCRAMBLE_TRY_IT_ENABLED` | `false` | `false` |
| `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS` | `true` | `false` |
| `ALLOW_FAKE_DRIVER` | `true` (enables backend emulation and `features.fake_driver` via `/api/v1/config`) | `false` |
| `PROVISION_HOST_*_RESERVE_*` | `0` / `0` / `0` | `1` core / `2048` MB / `20` GB |
| `AUDIT_STRICT_GRANTS` | `false` | `true` |
| `MAIL_FROM_ADDRESS` | `hello@ognivo.local` | `noreply@example.com` |

*(For `AUDIT_*` and `DB_MIGRATION_*` variables, see [security-and-audit.md](../architecture/security-and-audit.md#3-audit-environment-variables)).*

---

## 4. Container Healthchecks & Performance Tuning

In production these services define native Docker healthchecks:
- **Backend (`php-fpm`):** FastCGI ping (`cgi-fcgi` against `127.0.0.1:9000/ping`)
- **Webserver (`nginx`):** HTTPS probe (`curl -k -f`) to `https://127.0.0.1/up`
- **Frontend (`nextjs`):** HTTP probe (`wget --spider`) to `http://127.0.0.1:3000/`
- **Reverb:** HTTP probe to `http://127.0.0.1:8080/`
- **Console proxy:** HTTP probe (`wget --spider`) to `http://127.0.0.1:8085/health`
- **MySQL & Redis:** Native CLI pings with credentials (`mysqladmin ping`, `redis-cli -a "$REDIS_PASSWORD" ping`)

The queue worker containers (`queue-worker`, `queue-worker-heavy`), scheduler, Incus events listener, and cert-init containers do not define a healthcheck.

### Performance Tuning
- **OPcache Production Settings:** Bytecode validation is disabled (`opcache.validate_timestamps=0`), memory is allocated at 256MB with 20,000 max accelerated files, eliminating filesystem `stat()` overhead on immutable container environments.
- **Nginx FastCGI Timeout:** Configured to `90s` (`fastcgi_read_timeout 90;` and `fastcgi_connect_timeout 90;`) preventing hanging backend calls from monopolizing FPM worker slots.

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
15. `ALLOW_FAKE_DRIVER=true` (or `1`).
16. `AUDIT_STRICT_GRANTS=false` (or `0` / disabled).
17. `SESSION_SECURE_COOKIE=false` (or `0` / unset).
18. `APP_URL` empty, not using `https://`, or pointing to a local/loopback host (`localhost`, `127.0.0.1`, `::1`).
19. `SESSION_SAME_SITE` not set to `strict`.
20. `SESSION_DOMAIN` non-empty.
21. `SESSION_COOKIE` not starting with `__Host-`.
22. `SANCTUM_STATEFUL_DOMAINS` empty.
23. `CONSOLE_ALLOWED_ORIGINS` empty or containing wildcard `*`.

Run `make secrets` to generate valid values for all secret entries above.

The check does **not** validate every security-relevant setting. Review these yourself before going live: `APP_URL` (must match your real domain rather than the example `https://panel.example.com`), `SANCTUM_STATEFUL_DOMAINS` (your real domain), `CONSOLE_ALLOWED_ORIGINS` (your real origin), `SCRAMBLE_ALLOW_PUBLIC` (keep `false` for admin-only API docs), `HYPERVISOR_ALLOW_PRIVATE_ENDPOINTS` (only `true` if your nodes are on private addresses), and `TRUSTED_PROXIES` if Nginx is not on a private Docker network.

---

## 6. `APP_KEY` Rotation Procedure

`APP_KEY` encrypts `panel_certificates.private_key`, `hosts.token`, user secrets / password pre-hashes, and the SMTP password stored in `system_settings` (`smtp_settings`). Because Docker Compose injects `.env.production` (or `.env.development`) variables at container creation time, rotating `APP_KEY` requires recreating the containers (`make up-prod` / `make up-dev`) before and after re-encrypting database secrets.

### Prerequisites

> [!IMPORTANT]
> Before rotating keys, take a full backup of the production database (`mysqldump` or volume snapshot) and back up the current `.env.production` (or `.env.development`) file.

### Canonical 6-Step Procedure

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

4. **Re-encrypt all database secrets:**
   ```bash
   docker exec -it ognivo-backend php artisan app:reencrypt-secrets --include-users
   # development container: ognivo-backend-dev
   ```
   User secrets and password pre-hashes are also re-encrypted lazily on access or login; passing `--include-users` forces scanning and re-encrypting them immediately. You can run the command with `--dry-run` beforehand to simulate re-encryption without persisting changes.

5. **Verify key migration status and connectivity:**
   ```bash
   docker exec -it ognivo-backend php artisan app:reencrypt-secrets --status
   # development container: ognivo-backend-dev
   ```
   Confirm that `--status` reports `0` secrets on a previous key and exits `0`. Then verify hypervisor connectivity in the Admin UI (**Compute Nodes** -> **Verify connection**) and send an SMTP test email from the Admin panel (**Settings** -> **Send test email**).

6. **Clear `APP_PREVIOUS_KEYS` (ONLY when `--status` reports `0`):**
   Clear `APP_PREVIOUS_KEYS=""` in `.env.production` (or `.env.development`) and run `make up-prod` (or `make up-dev`) again so the old key is purged from the container environment.

> [!WARNING]
> If you rely on lazy migration instead of running `--include-users`, keep `APP_PREVIOUS_KEYS` set until all users have logged in and migrated. Removing `APP_PREVIOUS_KEYS` earlier makes any remaining unmigrated secrets or passwords undecryptable.

### Rollback Procedure

If `app:reencrypt-secrets --status` reports a non-zero count, or if decryption errors appear after removing the old key, restore the old key into `APP_PREVIOUS_KEYS` in `.env.production` (or `.env.development`) and redeploy with `make up-prod` (or `make up-dev`). Nothing is lost as long as the old key remains available in `APP_PREVIOUS_KEYS`.

### Troubleshooting

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| "The MAC is invalid" / `Illuminate\Contracts\Encryption\DecryptException` | `APP_PREVIOUS_KEYS` is missing the key used to encrypt the record, or the key was corrupted during copy-paste. | Restore the previous key into `APP_PREVIOUS_KEYS`, verify the format (`base64:...`), and restart containers with `make up-prod` (or `make up-dev`). |
| `--status` reports non-zero records on previous keys | Incomplete re-encryption or unmigrated user secrets. | Re-run `docker exec -it ognivo-backend php artisan app:reencrypt-secrets --include-users` (or `ognivo-backend-dev` in development). Do **not** clear `APP_PREVIOUS_KEYS` until `--status` exits `0`. |
| `app:reencrypt-secrets` exits with code `1` | Decryption error on one or more model attributes or the stored SMTP password. | Inspect container logs, verify `APP_PREVIOUS_KEYS`, and run with `--dry-run` to locate the failing model record. |

`AUDIT_HMAC_KEY` is rotated separately through `AUDIT_HMAC_KEY_ID` and `AUDIT_PREVIOUS_HMAC_KEYS` (JSON or comma-separated `id:key` pairs); see [security-and-audit.md](../architecture/security-and-audit.md#4-hmac-sealing-key-rotation-procedure).
