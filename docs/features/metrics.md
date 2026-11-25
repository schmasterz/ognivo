# Instance Metrics

Per-instance historical resource telemetry for KVM virtual machines and LXC containers running on Incus hypervisors.

---

## 1. Overview

Ognivo collects per-instance historical resource metrics by scraping Incus's Prometheus-format `GET /1.0/metrics` endpoint through `HypervisorGateway` over mTLS with SPKI public-key pinning.

Collected metrics per instance:
- **CPU utilization (`%`)**: Derived from cumulative CPU nanoseconds across guest/container cores.
- **Memory (`used` / `total` bytes)**: Active memory usage against allocated memory capacity.
- **Root filesystem (`/` `used` / `total` bytes)**: Space consumed on the instance's root mount.
- **Disk I/O (`read` / `write` B/s)**: Aggregated throughput across whole block devices.
- **Network traffic (`RX` / `TX` B/s)**: Aggregated throughput across non-loopback network interfaces.

Telemetry is stored in compact `RANGE`-partitioned MySQL 8.4 tables, served with tenant-isolated Redis response caching (`ETag`), and rendered as responsive SVG charts on the instance detail modal.

---

## 2. Feature Flag (`metrics.enabled` / `METRICS_ENABLED`)

Instance metrics ship **enabled by default** in `1.0.0-alpha.2` (`METRICS_ENABLED=true` in `.env` / `config('metrics.enabled')`).

To disable metrics, set `METRICS_ENABLED=false` in `.env.development` or `.env.production` and recreate the containers (`make up-dev` or `make up-prod`). See [Configuration Reference](../operations/configuration.md#311-instance-metrics--telemetry).

When `METRICS_ENABLED=false`:
- No `GET /1.0/metrics` requests are sent to hypervisor nodes (`metrics:collect` performs no scrapes).
- The metrics query API returns HTTP `503 Service Unavailable` with error code `metrics_disabled`.
- The **Metrics** tab in the instance detail modal is hidden in the frontend UI.
- Local maintenance commands (`metrics:rollup` and `metrics:prune`) continue running on schedule so any previously collected data remains aggregated, partitioned, and bounded by retention limits.

---

## 3. KVM Guest Agent Requirement (`incus-agent`)

Metric availability depends on the virtualization type and guest configuration:

- **LXC Containers:** Do not require an agent. The host Linux kernel and cgroups expose CPU, memory, root filesystem, block I/O, and network statistics directly to Incus.
- **KVM Virtual Machines:**
  - **Always available from host kernel / QEMU:** CPU utilization, Disk Read/Write B/s, and Network RX/TX B/s.
  - **Requires `incus-agent` inside the guest:** VM memory usage (`incus_memory_*`) and root `/` filesystem usage (`incus_filesystem_*`).

When `incus-agent` is not installed or not running inside a KVM guest, Ognivo stores `NULL` for those fields alongside an availability bitmask, and the UI explicitly displays **`Metric not available`** rather than misleading zeros.

---

## 4. Counter Semantics & Gap Handling

Incus exposes CPU, disk I/O, and network metrics as cumulative monotonic counters rather than instantaneous rates.

- **Rate Conversion:** Each collection cycle compares the latest counter reading against the prior counter state stored in Redis (`metrics:counters:{vm_id}`).
- **Explicit Gaps (`reason: "gap"`):** Ognivo records an explicit gap rather than emitting negative rates, artificial spikes, or false zeros whenever:
  - A counter decreases (for example, following an instance reboot or hypervisor restart).
  - Redis has no prior counter state (first collection cycle or cache eviction/seeding).
  - The elapsed interval between consecutive samples exceeds **$2.5\times$ the configured collection interval** (`METRICS_INTERVAL_SECONDS`).

---

## 5. Three-Tier Storage, Retention Defaults & UI Ranges

To keep database growth predictable while serving both high-resolution short windows and multi-week trends, telemetry is organized into three time-series tiers plus an on-change totals table.

### Storage Tiers

| Tier | Resolution | Aggregation | Default Retention | Table |
| :--- | :--- | :--- | :--- | :--- |
| **Raw** | `60s` default (`METRICS_INTERVAL_SECONDS`) | Point samples | `12 hours` (`METRICS_RETENTION_RAW_HOURS`) | `vm_metric_samples` |
| **Rollup 5 min** | `5 minutes` | `avg`, `min`, `max` | `8 days` (`METRICS_RETENTION_5M_DAYS`) | `vm_metric_rollups_5m` |
| **Rollup 1 hour** | `1 hour` | `avg`, `min`, `max` | `35 days` (`METRICS_RETENTION_1H_DAYS`) | `vm_metric_rollups_1h` |
| **Totals** | On change only | Capacity metadata | Retained while instance data exists | `vm_metric_totals` |

**Why `12h / 8d / 35d`?** Each retention default is sized to cover only the UI time ranges that query that tier, plus a small safety buffer for partition boundaries and freshness seams:
- `12 hours` of raw samples covers the `1h` and `6h` ranges without retaining days of per-minute rows.
- `8 days` of 5-minute rollups covers the `24h` and `7d` ranges.
- `35 days` of 1-hour rollups covers the `30d` range.

### UI Query Ranges & Auto-Refresh

| Range | Primary Source Tier | Query Bucketing | Max Points / Series | Freshness Seam & Auto-Refresh |
| :--- | :--- | :--- | :--- | :--- |
| `1h` | Raw | Native (`60s` default) | `<= 500` | Served from raw samples; visibility-gated auto-refresh |
| `6h` | Raw | Native (`60s` default) | `<= 500` | Served from raw samples; visibility-gated auto-refresh |
| `24h` | Rollup 5 min | `5m` buckets | `<= 500` | Trailing unrolled window filled from raw samples; visibility-gated auto-refresh |
| `7d` | Rollup 5 min | Widened to `25m` buckets | `<= 500` | Trailing unrolled window filled from raw samples; visibility-gated auto-refresh |
| `30d` | Rollup 1 hour | Widened to `2h` buckets | `<= 500` | Trailing unrolled window filled from `5m` rollup and raw tiers; visibility-gated auto-refresh |

Auto-refresh polling in the UI is visibility-gated (paused when the browser tab or Metrics tab is inactive) and uses range-appropriate intervals.

---

## 6. Storage Growth Estimation Formula

You can estimate total MySQL row counts for **`N`** monitored instances using the following formulas:

```text
raw rows        = N × (86400 / interval_s) × raw_days
rollup-5m rows  = N × 288 × rollup5m_days
rollup-1h rows  = N × 24  × rollup1h_days
```

### Example: `N = 500` Instances at Default Settings

With `interval_s = 60`, `raw_days = 0.5` (`12 hours`), `rollup5m_days = 8`, and `rollup1h_days = 35`:

- **Raw (`vm_metric_samples`):** `500 × (86400 / 60) × 0.5 = 360,000` (`0.36M` rows)
- **Rollup 5m (`vm_metric_rollups_5m`):** `500 × 288 × 8 = 1,152,000` (`~1.15M` rows)
- **Rollup 1h (`vm_metric_rollups_1h`):** `500 × 24 × 35 = 420,000` (`0.42M` rows)
- **Total:** `~1.93M` rows, occupying **`~0.38 GB`** in MySQL 8.4 including InnoDB page overhead — staying well under the `0.5 GB` storage budget.

---

## 7. Excluding Metrics from Database Backups

The four metrics tables contain consumable historical telemetry that can be rebuilt over time and do not hold account, quota, IPAM, certificate, or audit records. Excluding them from logical `mysqldump` backups keeps core control-plane backups fast and compact:

```bash
mysqldump -u root -p ognivo \
  --ignore-table=ognivo.vm_metric_samples \
  --ignore-table=ognivo.vm_metric_rollups_5m \
  --ignore-table=ognivo.vm_metric_rollups_1h \
  --ignore-table=ognivo.vm_metric_totals > ognivo-core-backup.sql
```

---

## 8. Related Documentation

- **Environment Variables:** [Configuration Reference — Section 3.11](../operations/configuration.md#311-instance-metrics--telemetry)
- **Artisan Commands & Scheduled Tasks:** [Commands Reference](../operations/commands.md#32-hypervisors-incus--lxd)
- **Cross-Tenant Auditing & Threat Model:** [Security & Tamper-Evident Audit Log](../architecture/security-and-audit.md)
