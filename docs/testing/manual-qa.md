# Manual QA & API Verification Guide

End-to-end (E2E) manual verification workflows and ready-to-run `curl` API recipes for **Ognivo**. For seeded accounts, quota groups, and nodes, see [Development Environment](../getting-started/development.md#3-seeded-demo-data).

---

## 1. End-to-End (E2E) Browser Scenarios

Open the development panel at **`http://localhost`**.

### Scenario A: Theme Switching (Light, Dark & OS Default)
1. On first visit without saved preferences, the UI inspects `prefers-color-scheme` and matches your OS theme.
2. Click the **Theme Toggle** in the top navigation bar and switch between **Light** (`#f8fafc`) and **Dark** (`#090d16`).
3. Verify the preference persists in `localStorage` (`theme_mode`).

### Scenario B: Bilingual Internationalization (`en` & `bg`)
1. On first visit, the UI inspects `navigator.languages` and loads Bulgarian (`bg`) if matched, falling back to English (`en`).
2. Click the **Language Toggle** (Globe icon) in the top navbar and switch to **Български (BG)**.
3. Verify that all headings, quota cards, tables, and modals translate immediately and API requests include `Accept-Language: bg` and `X-Locale: bg`.

### Scenario C: Dynamic Dashboard Scope (Cluster vs. Tenant Quotas)
1. Log in as Administrator (`admin@ognivo.local` / `password`).
2. With **All Tenants** selected, the top 4 cards show **Cluster Overview** (total instances, vCPU, RAM, and storage across all tenants).
3. Select a specific tenant from the **Filter Tenant** dropdown (e.g., `Demo Customer`): the top cards immediately switch to that tenant's quota limits and live consumption (displaying `∞ Unlimited` for `-1` limits).

### Scenario D: Instance Lifecycle & Suspend / Resume
1. On any `Running` instance, click **Suspend** (Pause icon) and confirm in the dialog.
2. Verify the instance transitions to `Suspended` (amber badge) via real-time WebSocket event.
3. Click **Start** (Play icon) to resume the instance back to `Running`.

### Scenario E: Quota Groups & Multi-Tenant Synchronization
1. Log in as Administrator and navigate to **Users & Quotas** (`/users`).
2. Click **Quota Groups & Plans** to inspect `Standard Tier` (`★ Default`), `Developer Tier`, and `Performance Unlimited`.
3. Edit any Quota Group and save: all tenants assigned to that group immediately receive the updated resource boundaries.
4. In the Create/Edit User modal, verify that individual quota inputs are locked while a preset Quota Group is selected, and unlock only when **No Group (Custom)** is chosen.

### Scenario F: Multi-Region Host Nodes & Ranged IPAM Pools
1. Open **Compute Nodes** (`/nodes`) from the Admin sidebar to inspect or onboard nodes (`eu-west-1`, `us-east-1`, `bg-sofia-1`):
   - Verify the **mTLS Panel Client Certificate** card (**Download .crt**, **Rotate**, and **Revoke Old Certificate** during rotation).
   - Filter the **Compute Nodes** table by keyword search (name, region, endpoint URL, fingerprint), connection/trust status, active state, and driver.
2. Navigate to **Networking & IPAM** (`/networking`) and click **Create Subnet Pool**:
   - Assign the pool to a specific host node (`host_id` is required).
   - Choose `routed`, `bridged`, `macvlan`, or `manual` mode (provide `parent_interface` for non-manual modes), run **Preflight Check** to verify hypervisor reachability and parent NIC existence/type, and open **View Pool & IPs** (`PoolDetailModal`) to inspect pool addresses and free IPs.

### Scenario G: Virtual Machine Snapshots
1. Log in as Customer (`customer@ognivo.local` / `password`), open any instance details modal, and select the **Snapshots** tab.
2. Observe the quota counter (`0 / 3 Snapshots Used`).
3. Click **+ Create Snapshot**, enter `pre-release-baseline`, and submit:
   - Status starts as `creating` with a spinner and transitions to green `ready` via WebSocket push.
4. Test **Restore** (reverts state and returns to `ready`) and **Delete** (removes snapshot and frees quota headroom).

### Scenario H: Interactive Web Console & Tenant Privacy Boundary
1. **As Workload Owner (`customer@ognivo.local`):**
   - Click **Console** on a running instance.
   - Verify the `xterm.js` terminal connects (`Connected` badge), echoes shell commands, supports **Fullscreen**, **Boot Log** viewing, and **Reconnect**.
2. **As Administrator (`admin@ognivo.local`):**
   - Attempt to open a tenant's console or call `POST /api/v1/vms/{id}/console`.
   - Confirm access is rejected with `403 Forbidden` (*"Console access is restricted to the workload owner only. Administrators are denied guest access."*) and logged in the Audit Log as `vm.action.denied` (`console.ticket`).

---

## 2. API Verification via `curl`

Ognivo's API serves the first-party web interface only. It uses cookie-based Sanctum stateful sessions rather than API tokens: there is no endpoint to obtain a token, and `Authorization: Bearer ...` headers are rejected with `401`.

- **Session cookies & security:** `POST /api/v1/login` sets an `httpOnly`, `SameSite=Strict` session cookie (`ognivo_session` in development, `__Host-ognivo_session` in production) and returns `{ "user": ... }` with no token. A cookie jar holds a live authenticated session—never share it, and delete `"$JAR"` (`rm -f "$JAR"`) when finished.
- **`Origin` header:** Stateful requests require an `Origin` (or `Referer`) header whose host matches `SANCTUM_STATEFUL_DOMAINS` (which includes `localhost` in development) so Sanctum treats the request as stateful.
- **`X-XSRF-TOKEN` header:** State-changing requests (`POST`, `PUT`, `PATCH`, `DELETE`) require `X-XSRF-TOKEN` set to the URL-decoded `XSRF-TOKEN` cookie obtained from `GET /sanctum/csrf-cookie`; missing or mismatched tokens return `419`.
- **Dev vs. Prod base URL:** Use `BASE=http://localhost` locally and `BASE=https://panel.example.com` in production (add `-k` to `curl` only if testing against self-signed TLS certificates).

### 2.1 Session Setup & Authentication (Cookie Jar + CSRF)
```bash
BASE=http://localhost            # production: https://panel.example.com (add -k for self-signed certs)
JAR=$(mktemp)                    # cookie jar; delete it when done (rm -f "$JAR")

xsrf() {                         # current CSRF token, URL-decoded
  awk '$6=="XSRF-TOKEN"{print $7}' "$JAR" | tail -n1 | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'
}

api() {                          # usage: api METHOD /path [json-body]
  curl -s -b "$JAR" -c "$JAR" -X "$1" "$BASE/api/v1$2" \
    -H "Origin: $BASE" -H "Accept: application/json" -H "Content-Type: application/json" \
    -H "X-XSRF-TOKEN: $(xsrf)" ${3:+-d "$3"}
}

# Log in (sets the httpOnly session cookie; the response contains the user and no token)
curl -s -o /dev/null -c "$JAR" -H "Origin: $BASE" "$BASE/sanctum/csrf-cookie"
api POST /login '{"email":"admin@ognivo.local","password":"password"}'

# Following calls reuse the cookie jar
api GET /me
```

To switch accounts (for example, to test tenant-scoped actions as `customer@ognivo.local`), log out and log back in or use a separate cookie jar:
```bash
api POST /logout
```

### 2.2 Quota Groups Management
```bash
# List Quota Groups
api GET /admin/quota-groups

# Update a Quota Group (automatically syncs all assigned tenants)
api PUT /admin/quota-groups/1 '{
  "name": "Standard Tier (Upgraded)",
  "description": "Upgraded base plan",
  "max_vms": 5,
  "max_cpu_cores": 8,
  "max_ram_mb": 16384,
  "max_storage_gb": 200,
  "max_ips": 3,
  "max_snapshots": 5
}'
```

### 2.3 Host Nodes & IP Pools
```bash
# List active compute nodes
api GET /hosts

# Filter compute nodes as admin (search, status, is_active, driver)
api GET "/admin/hosts?search=Frankfurt&status=ok&is_active=true&driver=fake"

# Filter virtual machines by host node
api GET "/vms?host_id=1"
```

### 2.4 Virtual Machine Snapshots
```bash
# List snapshots for VM #1 (with quota metadata)
api GET /vms/1/snapshots

# Create a manual snapshot asynchronously (202 Accepted)
api POST /vms/1/snapshots '{"name": "daily-backup-01"}'

# Restore a ready snapshot (202 Accepted)
api POST /vms/1/snapshots/1/restore

# Delete a snapshot (202 Accepted)
api DELETE /vms/1/snapshots/1
```

#### Troubleshooting
- **`419` (CSRF token mismatch):** Refresh the CSRF cookie (`curl -s -o /dev/null -b "$JAR" -c "$JAR" -H "Origin: $BASE" "$BASE/sanctum/csrf-cookie"`) and retry.
- **`401` right after login:** Confirm `-H "Origin: $BASE"` is included and its host is listed in `SANCTUM_STATEFUL_DOMAINS`.
- **`401` / `403` later in a session:** Sessions expire after the 24-hour absolute cap or 120 minutes of inactivity; sign in again with `POST /api/v1/login`.

### 2.5 Interactive OpenAPI Documentation
- **Swagger / Scramble UI:** `http://localhost/docs/api`
- **OpenAPI v3.1 JSON Spec:** `http://localhost/docs/api.json`

**Access Control & Interactive Try-It:** Access is governed by the `viewApiDocs` authorization gate and Scramble environment flags. In `production`, `SCRAMBLE_ALLOW_PUBLIC=false` is the default, restricting `/docs/api` and `/docs/api.json` to authenticated users with the `admin` role (only `local` and `testing` environments allow public access when enabled). The interactive "Try it out" panel is also disabled by default (`SCRAMBLE_TRY_IT_ENABLED=false`).

**Automatic Updates:** The OpenAPI specification updates automatically. Dedoc Scramble statically analyzes PHP 8 controller route attributes (`Spatie\RouteAttributes\Attributes\*`), `FormRequest` validation rules, and `JsonResource` return types dynamically on request.

**Refreshing Caches:** If route or Scramble caching has been enabled, clear both caches so changes appear immediately:
```bash
docker exec ognivo-backend-dev php artisan route:clear && docker exec ognivo-backend-dev php artisan scramble:clear
```

**CLI Analysis & Export:** You can analyze routes for documentation issues and export `backend/api.json` locally using `make openapi`, or by running the Artisan commands directly:
```bash
docker exec ognivo-backend-dev php artisan scramble:analyze
docker exec ognivo-backend-dev php artisan scramble:export --path=api.json
```
CI automatically runs these checks and uploads the `openapi-spec` artifact on every push.
