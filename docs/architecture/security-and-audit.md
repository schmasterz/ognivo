# Security Architecture & Tamper-Evident Audit Log

This document describes Ognivo's defense-in-depth security controls, database privilege separation, tamper-evident audit subsystem, and HMAC key rotation procedures.

---

## 1. Platform Security Controls

- **Encrypted Hypervisor Credentials:** The panel's mTLS private key (`PanelCertificate::private_key`) and host tokens (`Host::token`) are encrypted at rest in MySQL using AES-256-CBC (`encrypted` Eloquent cast) and passed in-memory via `CURLOPT_SSLCERT_BLOB` / `CURLOPT_SSLKEY_BLOB` (with a transient `0600` tempfile fallback in `sys_get_temp_dir()` unlinked immediately after the request).
- **Stateful Cookie Sessions, CSRF Protection & Server-Side Logout:** Browser authentication uses Laravel Sanctum stateful SPA session cookies (`HttpOnly`, `Secure` in production, `SameSite=Strict`, `Path=/`, no `Domain`; named `__Host-ognivo_session` in production and `ognivo_session` otherwise) over a same-origin Nginx topology without CORS. Sessions enforce a 120-minute idle timeout (background polling `GET` requests and `/api/v1/broadcasting/auth` with `X-Ognivo-Activity: passive` do not extend it) and a 24-hour absolute cap (`SESSION_ABSOLUTE_LIFETIME=1440`). Session IDs are regenerated on login and impersonation transitions, and sessions are invalidated server-side on `POST /api/v1/logout`. State-changing requests are protected by CSRF double-submit tokens (`GET /sanctum/csrf-cookie` + `X-XSRF-TOKEN`, returning `419` on mismatch) and same-origin verification (`PreventRequestForgery`). Impersonation is session-based (30-minute limit, exposed via `GET /api/v1/me` `impersonation` field), Reverb private channels (`/api/v1/broadcasting/auth`) authenticate via the session cookie and `X-XSRF-TOKEN` (`REVERB_ALLOWED_ORIGINS` enforces WebSocket `Origin` checks), and the strict Nginx CSP (`docker/nginx.prod.conf`) remains active as defense in depth. The HTTP API is currently intended for the first-party web interface only; public Bearer token authentication is disabled (Bearer tokens are rejected with `401`).
- **Strict Tenant Console Privacy:** Guest console sessions (`POST /api/v1/vms/{id}/console`) are restricted exclusively to the workload owner via `VirtualMachinePolicy::viewConsole`. Administrators and active admin impersonation sessions are denied access (`403 Forbidden`) and logged.
- **Cross-Tenant Metrics View Auditing (`vm.metrics.viewed`):** When an administrator (directly or while impersonating) views metrics of another tenant's instance, Ognivo records a `vm.metrics.viewed` audit event once per `(admin, instance)` per 15-minute window (`METRICS_AUDIT_WINDOW_SECONDS=900`) tracked in Redis, without ever storing metric data in the audit log.
- **Rate Limiting on Hypervisor Mutations:** Destructive and state-changing actions (`POST /api/v1/vms/{id}/action`, snapshot mutations) are protected by `throttle:vm-actions` (10 requests/minute per customer).
- **Internal Network Isolation:** In production, MySQL (`3306`), Redis (`6379`), Reverb (`8080`), `console-proxy` (`8085`), and Next.js (`3000`) are bound exclusively to the internal Docker network; only Nginx (`80`/`443`) is published to the host.

### 1.1 Cryptographic Boundaries & Data Classification

For key rotation steps, follow the canonical runbook in [configuration.md — APP_KEY Rotation Procedure](../operations/configuration.md#6-app_key-rotation-procedure).

- **Symmetrically Encrypted with `APP_KEY` (AES-256-CBC):** `PanelCertificate::private_key` (panel mTLS private key), `Host::token` (hypervisor trust tokens), and `system_settings.smtp_settings['password']` (SMTP authentication password). Ephemeral console tickets are encrypted with HKDF derived from `APP_KEY`. Losing `APP_KEY` renders this data permanently unrecoverable.
- **Hashed with per-user salt & `APP_KEY` pepper (one-way):** User passwords (`User::$password`) are NOT symmetrically encrypted; they use a per-user Base62 salt (`password_salt`) and an HMAC-SHA256 pre-hash peppered with `APP_KEY`, followed by Bcrypt (`Hash::make(...)`). They cannot be decrypted or re-encrypted offline without the user's plaintext password; during key rotation they are upgraded lazily upon user authentication as long as the old key is retained in `APP_PREVIOUS_KEYS` (or scanned via `--include-users`).
- **Signed with HMAC (`AUDIT_HMAC_KEY`):** Audit seals (`audit_seals`) use SHA-256 hash chaining signed with `AUDIT_HMAC_KEY` (`AUDIT_HMAC_KEY_ID`). This is rotated separately using `AUDIT_PREVIOUS_HMAC_KEYS` as described in [Section 4](#4-hmac-sealing-key-rotation-procedure).

### 1.2 Threat Model Note: Host-Side Usage Metrics vs Guest Content

Administrators and hosting operators are permitted to view resource utilization metrics (CPU %, memory usage, root filesystem usage, disk I/O rates, and network traffic rates) of tenant instances (`VirtualMachinePolicy::view`), with cross-tenant admin views audited via `vm.metrics.viewed`. This telemetry represents host-side hypervisor metadata exposed by Incus (`GET /1.0/metrics`) for capacity planning, billing verification, and abuse/health diagnostics. In contrast, interactive guest console access (`VirtualMachinePolicy::viewConsole`) and guest filesystem contents remain strictly restricted to the workload owner.

---

## 2. Tamper-Evident Audit Architecture

The audit subsystem records all user, administrative, and automated actions across the platform with three layers of tamper resistance:

### 2.1 Transaction Coupling & Rollback Protection
- **Success Events:** Written within the same database transaction as the business mutation (fail-closed: if the audit insert fails, the mutation rolls back).
- **Denied / Failed Events:** Written via a dedicated `audit` database connection so transaction rollbacks cannot erase evidence of unauthorized or failed attempts.

### 2.2 Database Privilege Separation (Strict Grants Mode)
- In production (`AUDIT_STRICT_GRANTS=true`), the main application database user (`ognivo_user`) and `audit_writer` are granted **only `INSERT` and `SELECT`** on `audit_logs` and `audit_seals`. Neither user can `UPDATE`, `DELETE`, or `TRUNCATE` audit records.
- Maintenance tasks (`audit:prune`, `audit:pseudonymise`) execute over a separate `audit_maintenance` connection, while schema migrations run as `migration_user`.

### 2.3 Cryptographic Sealing (SHA-256 Hash Chain + HMAC)
- Periodic sealing (`php artisan audit:seal`) groups audit rows into blocks in `audit_seals`.
- Each seal computes a SHA-256 hash chaining to the previous seal and signs it with an HMAC key (`AUDIT_HMAC_KEY`) tagged with `key_id` (`AUDIT_HMAC_KEY_ID`).
- Secondary structured JSON logs are written to the daily log channel (and optionally mirrored to `stderr` via `AUDIT_LOG_STDERR=true`).

### 2.4 PII Isolation & GDPR Pseudonymisation
- Personal identifiers (names, emails, IP addresses, user agents) are excluded from the sealed cryptographic digest.
- When a user is deleted, `php artisan audit:pseudonymise` hashes their identity using `AUDIT_PSEUDONYM_SALT` without breaking historical seal verification.

---

## 3. Audit Environment Variables

All audit secrets are initialized idempotently by `make secrets` (`scripts/gen-secrets.sh`):

| Variable | Default / Format | Description |
| :--- | :--- | :--- |
| `AUDIT_DB_USERNAME` | `audit_writer` | Database user with `INSERT, SELECT` on `audit_logs` and `audit_seals` |
| `AUDIT_DB_PASSWORD` | *(Random generated)* | Password for `audit_writer` |
| `AUDIT_MAINT_DB_USERNAME` | `audit_maintenance` | Database user for `audit:prune` and `audit:pseudonymise` |
| `AUDIT_MAINT_DB_PASSWORD` | *(Random generated)* | Password for `audit_maintenance` |
| `DB_MIGRATION_USERNAME` | `migration_user` | DDL administrator used during migrations and `audit:grants` |
| `DB_MIGRATION_PASSWORD` | *(Random generated)* | Password for `migration_user` |
| `AUDIT_STRICT_GRANTS` | `true` (prod) / `false` (dev) | Enforces strict MySQL privilege separation |
| `AUDIT_HMAC_KEY_ID` | `v1` | Identifier of the active HMAC sealing key |
| `AUDIT_HMAC_KEY` | *(64 hex chars / 32 bytes)* | Secret key used to sign seals (never overwrite without rotating) |
| `AUDIT_PREVIOUS_HMAC_KEYS`| *(Optional: `v1:<key>`)* | Comma-separated historical keys retained for verifying older seals |
| `AUDIT_PSEUDONYM_SALT` | *(64 hex chars / 32 bytes)* | Salt for hashing deleted user identities |
| `AUDIT_RETENTION_DAYS` | `400` | Retention period in days before archiving and pruning |
| `AUDIT_SEAL_DELAY_SECONDS`| `120` | Grace window before sealing rows to allow in-flight transactions |
| `AUDIT_ATTEMPTED_EMAIL_DAYS`| `30` | Retention period for failed unauthenticated login email records |
| `AUDIT_LOG_STDERR` | `false` | Mirror structured audit JSON lines to container `stderr` |

> [!IMPORTANT]
> In production (`APP_ENV=production`), `App\Audit\AuditConfigValidator` halts any `audit:*` command immediately if `AUDIT_HMAC_KEY` or `AUDIT_PSEUDONYM_SALT` is empty or set to a placeholder (`CHANGE_ME`).

---

## 4. HMAC Sealing Key Rotation Procedure

Every row in `audit_seals` stores the `key_id` (`v1`, `v2`, etc.) used when signing that seal. During verification (`php artisan audit:verify`), the verifier looks up the matching secret from `config('audit.hmac.keys')`.

### Step-by-Step Rotation

1. **Generate a new 32-byte hex key:**
   ```bash
   openssl rand -hex 32
   ```

2. **Increment the key identifier** (e.g., from `v1` to `v2`).

3. **Update `.env.production`:**
   Move the previous key into `AUDIT_PREVIOUS_HMAC_KEYS` and set the new active key:
   ```ini
   AUDIT_PREVIOUS_HMAC_KEYS=v1:<previous_v1_hex_key>
   AUDIT_HMAC_KEY_ID=v2
   AUDIT_HMAC_KEY=<new_v2_hex_key>
   ```

4. **Restart the production containers:**
   ```bash
   make up-prod
   ```

5. **Verify seal integrity across all historical and new seals:**
   ```bash
   docker exec -i ognivo-backend php artisan audit:verify
   ```
