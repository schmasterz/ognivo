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
2. Click **Quota Groups & Plans** to inspect `Standard Tier` (`★ Default`), `Developer Tier`, and `Enterprise Unlimited`.
3. Edit any Quota Group and save: all tenants assigned to that group immediately receive the updated resource boundaries.
4. In the Create/Edit User modal, verify that individual quota inputs are locked while a preset Quota Group is selected, and unlock only when **No Group (Custom)** is chosen.

### Scenario F: Multi-Region Host Nodes & Ranged IPAM Pools
1. Open **Host Nodes & Regions** from the Admin sidebar to inspect or onboard nodes (`eu-west-1`, `us-east-1`, `bg-sofia-1`).
2. Navigate to **Networking & IPAM** (`/networking`) and click **Create Subnet Pool**:
   - Assign the pool to a specific host node or leave global.
   - Choose `routed` or `manual` mode and run the **Preflight Check** to verify hypervisor reachability and parent NIC existence.

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
   - Attempt to open a tenant's console or call `POST /api/vms/{id}/console`.
   - Confirm access is rejected with `403 Forbidden` (*"Console access is restricted to the workload owner only. Administrators are denied guest access."*) and logged in the Audit Log as `vm.action.denied` (`console.ticket`).

---

## 2. API Verification via `curl`

### 2.1 Authenticate & Obtain Bearer Token
```bash
curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@ognivo.local","password":"password"}'
```

### 2.2 Quota Groups Management
```bash
# List Quota Groups
curl -s http://localhost/api/admin/quota-groups \
  -H "Authorization: Bearer <ADMIN_TOKEN>"

# Update a Quota Group (automatically syncs all assigned tenants)
curl -s -X PUT http://localhost/api/admin/quota-groups/1 \
  -H "Authorization: Bearer <ADMIN_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
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
curl -s http://localhost/api/hosts \
  -H "Authorization: Bearer <USER_TOKEN>"

# Filter virtual machines by host node
curl -s "http://localhost/api/vms?host_id=1" \
  -H "Authorization: Bearer <USER_TOKEN>"
```

### 2.4 Virtual Machine Snapshots
```bash
# List snapshots for VM #1 (with quota metadata)
curl -s http://localhost/api/vms/1/snapshots \
  -H "Authorization: Bearer <USER_TOKEN>"

# Create a manual snapshot asynchronously (202 Accepted)
curl -s -X POST http://localhost/api/vms/1/snapshots \
  -H "Authorization: Bearer <USER_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"name": "daily-backup-01", "stateful": false}'

# Restore a ready snapshot (202 Accepted)
curl -s -X POST http://localhost/api/vms/1/snapshots/1/restore \
  -H "Authorization: Bearer <USER_TOKEN>"

# Delete a snapshot (202 Accepted)
curl -s -X DELETE http://localhost/api/vms/1/snapshots/1 \
  -H "Authorization: Bearer <USER_TOKEN>"
```

### 2.5 Interactive OpenAPI Documentation
- **Swagger / Scramble UI:** `http://localhost/docs/api`
- **OpenAPI v3 JSON Spec:** `http://localhost/docs/api.json`
