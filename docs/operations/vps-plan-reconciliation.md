# VPS Plan Change Reconciliation Runbook

Operational guide for triaging stalled or ambiguous VPS plan-change operations (`reconciliation_required`), running manual administrative resolution, and configuring automated recovery sweeps.

---

## 1. When `reconciliation_required` Occurs

An asynchronous plan change (`ApplyVpsPlanChangeJob` on the `high` queue) enters `reconciliation_required` instead of `completed` or `failed` whenever the panel cannot prove that the hypervisor state matches either `old_resources` or `new_resources`, or when a partial apply / timeout leaves the instance in an uncertain state:

- **`VERIFY_RESOURCE_MISMATCH`:** Hypervisor operation completed, but `getInstanceResourceSpec()` did not match `new_resources` (or `old_resources`).
- **`VERIFY_HOST_UNREACHABLE`:** Compute node became unreachable during post-apply verification.
- **`PARTIAL_APPLY` / `APPLY_TIMEOUT`:** Multi-step update partially succeeded (for example CPU/RAM updated while disk or NIC limits failed) or exceeded `vps.apply_deadline_minutes`.
- **`RECONCILE_RESOURCE_MISMATCH`:** Scheduled `ReconcileVpsPlanChangeJob` inspected a stalled `applying` operation and observed resources matching neither `old_resources` nor `new_resources`.

While in `reconciliation_required`:
- The operation continues to hold a concurrency lock on the VM (`ANOTHER_CHANGE_IN_PROGRESS` for new requests) and preserves its positive CPU/RAM/disk reservations in `ResourceReservationService`.
- When drift details are captured, Ognivo records an open `DriftReport` of kind `resource_mismatch` and emits the `@experimental` domain event `vps.plan_change.reconciliation_required`.

---

## 2. Triage Procedure

### 2.1 Inspect the Operation in Admin UI or API

1. **Admin UI:** Open **Plans** (`/admin/plans`) and select the **Plan Changes** tab (`AdminPlanChangesView`), then filter status by **`reconciliation_required`**.
2. **REST API:**
   ```http
   GET /api/v1/admin/vps/plan-changes?status=reconciliation_required
   GET /api/v1/admin/vps/plan-changes/{public_id}
   ```
   Review `stage`, `error_code`, `error_message`, `driver_operation_id`, `old_resources`, `new_resources`, and `virtual_machine` (`id`, `name`, `incus_project`, `status`).

### 2.2 Inspect the Live Instance on the Hypervisor

On the target compute node, check the actual Incus configuration and active operations for the instance (`--project <incus_project>`):

```bash
# Inspect live config, limits.cpu, limits.memory, root disk size, and NIC ingress/egress
incus config show <instance-name> --project <incus_project>

# Inspect runtime state and memory/disk allocation
incus info <instance-name> --project <incus_project>

# Check if an Incus operation is still running
incus operation list --project <incus_project>
```

Compare the observed hypervisor limits (`cpu_cores`, `ram_mb` in MiB = `bytes / 1048576`, `disk_gb` in GiB = `bytes / 1073741824`, and NIC `limits.ingress` / `limits.egress` in Mbit) against `old_resources` and `new_resources`.

---

## 3. Manual Resolution Actions (`complete`, `fail`, `retry`)

Once you have verified or corrected the hypervisor state, resolve the operation via the **Resolve** action in the Admin UI or via `POST /api/v1/admin/vps/plan-changes/{public_id}/resolve` (requires `vps.plan_change.manage` permission):

```http
POST /api/v1/admin/vps/plan-changes/{public_id}/resolve
Content-Type: application/json

{
  "action": "complete",
  "note": "Verified on Incus node eu-west-1 that CPU/RAM/disk match target plan."
}
```

### Action Behavior & Guardrails

`VpsPlanChangeService::resolve()` performs a **fresh hypervisor read** (`getInstanceResourceSpec($vm)`) before committing any resolution. If the compute node is unreachable, the API returns **`503 Service Unavailable`** with code `HOST_UNREACHABLE`.

| `action` | Live Hypervisor Requirement | Effects on Database, Drift & Events |
| :--- | :--- | :--- |
| **`complete`** | Observed resources **must match `new_resources`** (exact `cpu_cores`, `ram_mb * 1048576`, `disk_gb * 1073741824`, `net_download_mbps`, `net_upload_mbps`). Rejected with `422` otherwise. | Updates `VirtualMachine` resource columns and `vps_plan_version_id = to_version_id`, transitions plan change to `completed`, marks open `resource_mismatch` `DriftReport` rows resolved, records audit log, and dispatches `vps.plan_change.completed` and `instance.resized`. |
| **`fail`** | Observed resources **must match `old_resources`**. Rejected with `422` otherwise (if the node still has partial/target resources, revert them on Incus or use `complete`/`retry`). | Transitions plan change to `failed` with `error_code = 'RECONCILED_FAILED'`, leaves `VirtualMachine` on its original plan/resources, releases reserved capacity/quota, marks open `resource_mismatch` drift reports resolved, records audit log, and dispatches `vps.plan_change.failed`. |
| **`retry`** | VM must be in `running` or `stopped` state (any observed resource state is allowed). | Resets `stage = 'validating'`, clears `driver_operation_id`, `error_code`, and `error_message`, assigns a fresh `apply_deadline`, transitions `reconciliation_required` → `applying`, marks open `resource_mismatch` drift reports resolved, records audit log, and re-dispatches `ApplyVpsPlanChangeJob`. |

---

## 4. Automated Reconciliation Sweep (`vps:reconcile-plan-changes`)

`php artisan vps:reconcile-plan-changes` (`App\Console\Commands\ReconcilePlanChangesCommand`) runs automatically **every 5 minutes** via the `scheduler` container (`withoutOverlapping(10)`):

```bash
# Run manually in development or production
docker exec -it ognivo-backend-dev php artisan vps:reconcile-plan-changes
docker exec -it ognivo-backend php artisan vps:reconcile-plan-changes
```

Each sweep executes three recovery passes:

1. **Stale `pending` re-dispatch:** Finds `pending` plan changes older than `vps.pending_stale_minutes` (default `15`), touches `updated_at`, records an audit entry (`actorLabel = 'vps:reconcile-plan-changes'`), and re-dispatches `ApplyVpsPlanChangeJob`.
2. **Stale `applying` reconciliation:** Finds `applying` plan changes past `apply_deadline` or older than `vps.applying_stale_minutes` (default `30`), and dispatches `ReconcileVpsPlanChangeJob`:
   - If the hypervisor matches `new_resources` → commits `completed` automatically.
   - If the hypervisor matches `old_resources` → marks `failed` (`NOT_APPLIED`).
   - If the hypervisor matches neither → transitions to `reconciliation_required` (`RECONCILE_RESOURCE_MISMATCH`) and creates a `DriftReport`.
3. **Expired `awaiting_authorization` cleanup:** Finds `awaiting_authorization` plan changes older than `vps.authorization_timeout_minutes` (default `60`), transitions them to `cancelled` with `error_code = 'AUTHORIZATION_TIMEOUT'`, records an audit log, and dispatches `vps.plan_change.cancelled`.

---

## 5. Configuration Reference (`backend/config/vps.php`)

All timings are configured in `backend/config/vps.php` and can be overridden via environment variables:

| Config Key (`config/vps.php`) | Environment Variable | Default | Description |
| :--- | :--- | :--- | :--- |
| `vps.authorizer_timeout_seconds` | `VPS_AUTHORIZER_TIMEOUT_SECONDS` | `3` | Maximum wall-clock seconds for synchronous `VpsProvisioningAuthorizerInterface` / `VpsPlanChangeAuthorizerInterface` calls (enforced outside DB transactions/locks). |
| `vps.authorization_timeout_minutes` | `VPS_AUTHORIZATION_TIMEOUT_MINUTES` | `60` | Maximum lifetime in minutes for an `awaiting_authorization` plan change before `vps:reconcile-plan-changes` cancels it with `AUTHORIZATION_TIMEOUT`. |
| `vps.resource_update_wait_seconds` | `VPS_RESOURCE_UPDATE_WAIT_SECONDS` | `30` | Maximum wait seconds for synchronous hypervisor resource updates before falling back to async operation polling (must remain strictly less than `ApplyVpsPlanChangeJob::$timeout = 120`). |
| `vps.apply_deadline_minutes` | `VPS_APPLY_DEADLINE_MINUTES` | `20` | Overall deadline (`apply_deadline` / `retryUntil()`) in minutes for `ApplyVpsPlanChangeJob` async polling and retries. |
| `vps.pending_stale_minutes` | `VPS_PENDING_STALE_MINUTES` | `15` | Age threshold in minutes before `vps:reconcile-plan-changes` re-dispatches a stalled `pending` plan change. |
| `vps.applying_stale_minutes` | `VPS_APPLYING_STALE_MINUTES` | `30` | Age threshold in minutes before `vps:reconcile-plan-changes` dispatches `ReconcileVpsPlanChangeJob` for a stalled `applying` plan change. |
