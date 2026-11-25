# Changelog

All notable changes to **Ognivo** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0-alpha.2] - 2026-12-01

### Added
- **Per-Node Overcommit Multipliers:** Moved CPU (`1.0x`–`16.0x`), RAM (`1.0x`–`4.0x`), and storage (`1.0x`–`4.0x`) overcommit multipliers from global environment variables to per-host database columns (`hosts.cpu_overcommit`, `hosts.ram_overcommit`, `hosts.disk_overcommit`), configurable in the **Create Compute Node** and **Edit Compute Node** modals and displayed in the **Compute Nodes** table.
- **Dynamic Per-Node Instance Limits in Provisioning Modal:** Exposed `max_instance` (`cpu_cores`, `ram_mb`, `disk_gb`) on `GET /api/hosts` (`TenantHostResource` and `HostNodeResource`) and updated `CreateVmModal` to dynamically cap and clamp vCPU, RAM, and storage inputs to the selected node's available single-instance capacity alongside user quota limits.
- **Enhanced Secret Re-encryption & Key Rotation Verification:** Added `--status`, `--dry-run`, and `--include-users` options to `php artisan app:reencrypt-secrets`. `--status` verifies secret encryption states across database models and exits non-zero if unmigrated secrets remain; `--dry-run` simulates re-encryption without database mutations; `--include-users` forces immediate scanning and re-encryption of user secrets alongside lazy access upgrades.
- **Instance Resource Metrics (`metrics.enabled`):** Per-instance historical telemetry (`1h`, `6h`, `24h`, `7d`, `30d`) for KVM virtual machines and LXC containers scraped from Incus `GET /1.0/metrics` via `HypervisorGateway`, stored in compact `RANGE`-partitioned MySQL 8.4 tables (`12h` raw, `8d` 5-minute rollups, `35d` 1-hour rollups, plus on-change totals), with tenant-isolated Redis response caching (`ETag`), 15-minute windowed cross-tenant admin view auditing (`vm.metrics.viewed`), per-node collector health & backoff, and responsive SVG charts on the instance detail modal (enabled by default via `METRICS_ENABLED=true`; additive migrations require no manual upgrade steps).
- **Frontend Runtime Configuration & Server Component `<ConfigProvider>`:** Decoupled `NEXT_PUBLIC_*` build arguments from Docker images (`frontend/Dockerfile.prod` and `docker-compose.prod.yml`). Runtime configuration is now resolved on the server in `layout.tsx` via Node.js environment variables and provided to the component tree via `<ConfigProvider>`, with in-memory caching in `config.ts`, dynamic Reverb WebSocket key (`ws_key`) and port resolution in `echo.ts`, and pre-paint theme initialization moved to static `/theme-init.js` to achieve zero inline script injection and strict CSP compliance.
- **Extension Registry & Manifest Discovery (`composer/semver: ^3.4`):** Implemented `ExtensionRegistry` scanner discovering extensions via Composer PSR-4 and `ognivo-extension.json` manifests with SemVer compatibility validation, and exposed admin API endpoints `GET /api/v1/extensions` and `GET /api/v1/extensions/{name}`.
- **Domain Events Catalog (17 Typed Events):** Defined `OgnivoEventInterface` and `AbstractOgnivoEvent` enforcing `ShouldDispatchAfterCommit` transaction safety across Instance (`instance.*`), Host (`host.*`), Storage (`storage.*`), and Access/System (`access.*` / `system.*`) events.
- **Service Contracts & Dynamic Container Binding:** Added core extension interfaces (`DnsProviderInterface`, `NotificationChannelInterface`, `BillingProviderInterface`, and `BackupTargetInterface`) with fallback bindings dynamically registered via `ExtensionServiceProvider`.
- **Service Tokens Subsystem:** Added `service_tokens` table with SHA-256 hashed storage, `srv_` token generation, granular abilities, `AuthenticateServiceToken` middleware setting `actor_type = 'system'`, and admin management API under `/api/v1/service-tokens`.
- **Webhooks Engine:** Added `webhook_endpoints` and `webhook_deliveries` tables, asynchronous queued delivery job (`DeliverWebhookJob`) with HMAC-SHA256 signature verification headers, exponential backoff retries, response payload truncation, and admin endpoints `GET|POST|DELETE /api/v1/webhooks`, `GET /api/v1/webhooks/{id}/deliveries`, and `POST /api/v1/webhooks/{id}/test`.
- **Idempotency Engine:** Added `idempotency_keys` table with SHA-256 request payload hashing, atomic distributed lock lifecycle (30s timeout / 24h retention), and `IdempotencyMiddleware` returning `X-Idempotent-Replay: true`, `409 Conflict` on concurrent in-flight requests, and `422 Unprocessable Entity` on fingerprint mismatch.
- **Frontend Slot, Navigation & Theming Registry:** Added `slotRegistry.ts` (supporting UI slots `instance.detail.tabs`, `instance.detail.actions`, `dashboard.widgets`), `navigationRegistry.ts` with role filtering in `Sidebar.tsx`, and `themeRegistry.ts` for dynamic CSS token overrides and brand logo slot in `OgnivoLogo.tsx`.
- **Reference Extension (`extensions/hello-ognivo`):** Reference extension demonstrating manifest configuration, service provider registration, and instance lifecycle event listening.
- **Contract Test Suite:** Added Pest contract tests `backend/tests/Contract/DomainEventContractTest.php` and `backend/tests/Contract/ExtensionInterfaceContractTest.php` verifying event and interface contracts, integrated into `.github/workflows/ci.yml`.
- **Extensions Documentation Hub:** Authored 7 comprehensive developer guides in `docs/extensions/` (`index.md`, `events-catalog.md`, `service-contracts.md`, `frontend-slots.md`, `webhooks-and-tokens.md`, `idempotency.md`, `stability-policy.md`) and integrated into `docs/README.md` and root `README.md`.
- **Isolated Queue Topology & Dual Supervisor Workers:** Introduced typed `QueueName` enum (`high`, `metrics`, `default`, `heavy`) separating job processing by latency profile. Long-running hypervisor operations (`reinstall`, `backup`, `snapshot`, `delete`, `image download`) are dispatched to the `heavy` queue, critical events to `high`, and telemetry scraping to `metrics`. Added dedicated `queue-worker-heavy` container alongside the primary worker in Docker Compose topologies.
- **Dedicated Snapshot Synchronization Command:** Introduced `php artisan snapshots:sync` (`SyncVmSnapshotsCommand`) scheduled every 10 minutes to decouple heavy snapshot drift audits from instance reconciliation.
- **In-Memory Certificate & Private Key Caching:** Added 60-second in-memory caching for active/retiring mTLS certificates and parsed OpenSSL key handles in `PanelCertificateService`, auto-invalidated on certificate generation or rotation.
- **Production OPcache & PHP-FPM Pool Tuning:** Added production `opcache.ini` (`validate_timestamps=0`, 256MB memory, 20,000 accelerated files) and `fpm-pool.conf` with configurable concurrency (`pm.max_children = ${PHP_FPM_MAX_CHILDREN:-30}`, `pm.max_requests = 1000`).
- **Pcntl Composer Requirement:** Explicitly declared `"ext-pcntl": "*"` dependency in `backend/composer.json` for resilient Unix signal handling in long-running CLI daemons.

### Changed
- **Single-Instance Physical Hardware Cap:** `HostCapacityGuard` now enforces that a single virtual machine or container cannot request more vCPU cores, RAM, or disk than the compute node's physical capacity (minus host reserves), even when per-node overcommit multipliers exceed `1.0x`.
- **Auto-Discovered Onboarding Capacity & Physical Cap Validation:** Onboarding a compute node now auto-discovers hardware capacity from the hypervisor by default instead of pre-filling placeholder values. Manual capacity overrides act strictly as a cap (`<=` physical hypervisor resources); values exceeding physical hardware capacity are rejected with HTTP 422 (`messages.host.capacity_exceeds_physical`).
- **Hardened APP_KEY Rotation Runbook (Safety Fix):** Updated the canonical key rotation runbook in `docs/operations/configuration.md` with explicit prerequisites, rollback steps, a troubleshooting matrix, and the mandatory verification rule requiring `app:reencrypt-secrets --status` to report `0` before purging `APP_PREVIOUS_KEYS`.
- **Core API Versioning Migration (`/api/v1`):** Fully migrated all REST API endpoints from `/api/*` to `/api/v1/*` in `route-attributes.php`, `channels.php`, Nginx configuration, `.env.*.example`, documentation, and all 53 backend feature test suites without backward compatibility alias.
- **Shortened Provisioning Critical Section:** Moved optimistic host selection, compute node capacity evaluation, virtualization capability checks, and image allowlist verification outside the provisioning database transaction in `VmProvisioningService`. Reserved the database transaction exclusively for authoritative row locks (`UserQuota` -> `Host`) and IP reservation, minimizing host lock holding times during concurrent provisioning bursts.
- **FastCGI Read Timeout Hardening:** Reduced Nginx `fastcgi_read_timeout` from 300s to 90s to fail fast on stuck processes and align web server timeouts with background worker delegation.
- **Multi-Host Incus Listener Resilience & Log Filtering:** Hardened `php artisan incus:listen` with exponential crash backoff (15s to 300s) on persistent connection failure, active MySQL connection ping/reconnection checks prior to event processing, and filtered verbose event diagnostics behind `-v`/`--verbose` flags.
- **Extension Contracts Stability Governance:** Marked unproven provider contracts (`DnsProviderInterface`, `NotificationChannelInterface`, and `BillingProviderInterface`) as `@experimental` (Developer Preview) in PHP docblocks and architecture documentation (`docs/extensions/service-contracts.md` and `docs/extensions/stability-policy.md`), preserving strict 1.0.0 SemVer freeze commitments for proven core interfaces (`BackupTargetInterface`, domain events, webhooks, frontend slots) while allowing provider contracts to evolve with real-world integrations.

### Performance
- **Eliminated Reconciliation N+1 Hypervisor Calls:** Refactored `VmReconciliationService` to ingest bulk instance state directly from hypervisor `listInstances()` responses instead of issuing sequential per-instance status queries. Added `withoutOverlapping(10)` mutex protection to the scheduled reconciliation command.
- **Hypervisor cURL Handler Reuse & Connection Pooling:** Implemented host-keyed `CurlMultiHandler` in `HypervisorGateway` enabling HTTP/1.1 and TLS Keep-Alive connection reuse across sequential hypervisor requests, eliminating redundant TLS handshakes.
- **IPAM SQL Aggregate Utilization:** Replaced in-memory IP allocation collection and PHP filtering with constant 2-query SQL aggregates (`COUNT(*)` / `SUM(CASE ...)`) in `IpManagementService::attachUtilizationToPools()`.
- **Streaming Gap IP Allocation Cursor:** Replaced full in-memory address scanning in `IpManagementService::allocateIp()` with an ordered binary address streaming cursor ($O(1)$ memory, $O(\text{gap})$ time), supporting large `/16`+ routed subnets without memory exhaustion.
- **Bounded Telemetry Rollups with Database Cursor Fallback:** Added bounded time-window chunking (1 hour for 5-minute rollups, 24 hours for 1-hour rollups) and database `MAX(bucket_ts)` cursor fallback to `VmMetricsRollupService`, preventing out-of-memory errors on Redis restarts or flushes.
- **Batched Redis Metrics Caching:** Refactored `VmMetricsCollectorService` to use Redis batch operations (`Cache::many()` and `Cache::putMany()`), cutting Redis round-trips by ~66% during multi-VM telemetry scraping cycles.

### Removed
- **Global Overcommit Environment Variables:** Removed deprecated `PROVISION_CPU_OVERCOMMIT`, `PROVISION_RAM_OVERCOMMIT`, and `PROVISION_DISK_OVERCOMMIT` from `backend/config/provisioning.php`, `.env.development.example`, and `.env.production.example` in favor of per-node overcommit configuration.
- **Build-Time Frontend Configuration Baking:** Removed `ARG NEXT_PUBLIC_*` and build arguments from `frontend/Dockerfile.prod` and `docker-compose.prod.yml`, allowing frontend Docker images to be built once and configured dynamically at container runtime.
- **Inline HTML Scripts in Frontend (`dangerouslySetInnerHTML`):** Completely removed all `dangerouslySetInnerHTML` occurrences from frontend layout in favor of Server Component props serialization and static pre-paint scripts.

## [1.0.0-alpha.1] - 2026-11-23

> **Pre-Release Notice (Alpha):**
> `v1.0.0-alpha.1` is the first public alpha preview of the **Ognivo 1.0.0** control plane. All core `1.0.0` subsystems are implemented and available for evaluation, staging deployments, and community feedback. While comprehensive automated test suites and security controls are in place, database schemas, internal APIs, and configuration keys may still evolve prior to the final `1.0.0` General Availability (GA) release. **Do not use in mission-critical production without external backups.**

### Vision for `1.0.0`

**Ognivo 1.0.0** establishes a modern, security-first open-source multi-tenant cloud control plane for **Incus** and **LXD** hypervisors, built with **Laravel 13 (PHP 8.5)**, **Next.js 16 (React 19)**, **Laravel Reverb**, and an isolated **Node.js 26 LTS mTLS Console Proxy**.

### Added in `1.0.0-alpha.1`

#### Compute & Workload Orchestration
- **KVM Virtual Machines & LXC Containers:** Unified provisioning and lifecycle state machine (`Provisioning`, `Running`, `Stopped`, `Suspended`, `Deleting`, `Deleted`, `Failed`).
- **VM Reinstall:** Non-destructive operating system reinstallation (`reinstall` lifecycle action with IP preservation and confirmation UI).
- **Atomic Provisioning & Compensation:** Race-free resource reservation using database row locks (`lockForUpdate`) and Redis mutexes, backed by automatic compensating rollbacks (`ProvisioningCompensator`) if hypervisor creation fails.
- **Per-Tenant Hypervisor Isolation:** Automatic provisioning and quota synchronization of dedicated Incus/LXD projects (`proj-{uuid}`) per tenant.
- **OS Image Catalog & Cloud-Init Networking:** Admin-managed image allowlist (any Incus image server) and automated Cloud-Init Netplan network configuration for Manual pools and for virtual machines on Routed pools.
- **Linux-Only Guest OS Catalog:** Restrict guest instance images in the UI and provisioning flow to Linux operating systems for the 1.0.0-alpha.1 release (Windows, BSD, and non-Linux guest families reserved for roadmap releases).
- **Compute Node Reachability Guard:** Preflight reachability enforcement blocking VM actions and snapshot operations with HTTP 422 (Host Unreachable) when the hypervisor compute node is unreachable.

#### Cryptographic Hypervisor Management & Drift Reconciliation
- **mTLS Onboarding & Trust Pinning:** Automated `secp384r1` X.509 client certificate generation, one-time trust token onboarding, SPKI public-key pinning (`CURLOPT_PINNEDPUBLICKEY` / `sha256//...` TOFU + re-trust workflow), and zero-downtime certificate rotation.
- **Host Capacity Discovery & Enforcement:** Automated sync of hypervisor CPU, memory, and disk resources (`php artisan hosts:sync-resources`), intelligent placement (`HostSelector`), and preflight overcommit checks (`HostCapacityGuard`).
- **Multi-Driver Architecture:** Pluggable `HypervisorDriverInterface` supporting `IncusDriver`, `LxdDriver`, and `FakeHypervisorDriver` for deterministic testing.
- **Live Event Streaming & Drift Detection:** Real-time hypervisor event daemon (`php artisan incus:listen`) combined with scheduled state reconciliation (`php artisan vm:reconcile`) and an administrative Drift Resolution UI.

#### Interactive Web Terminal
- **Isolated `console-proxy` Microservice:** Standalone Node.js 26 LTS service streaming live `xterm.js` terminal sessions over mTLS WebSockets using atomic single-use Redis tickets (`GETDEL`, 60 seconds by default) and a pinned upstream connection.
- **Exec Shell with Serial-Console Fallback:** Terminals open an exec shell by default. Virtual machines without a running guest agent fall back to the serial console. A read-only boot log is available alongside the terminal.
- **Session Limits & Idle Timeout:** Configurable limits for concurrent exec and console sessions per instance, a message size limit, and an idle timeout (`TERMINAL_*` settings).
- **Strict Tenant Terminal Privacy:** Policy-level guardrails (`VirtualMachinePolicy::viewConsole`) prevent administrators, even during active user impersonation, from accessing customer guest terminals.

#### Point-in-Time Snapshots
- **Crash-Consistent Disk Snapshots:** Asynchronous crash-consistent block storage snapshot creation, restoration, and deletion with per-VM concurrency locks and real-time WebSocket progress transitions.
- **Pluggable Backup Architecture:** Built around `BackupTargetInterface` with local hypervisor snapshot support, per-VM and global snapshot management views, and tier quota enforcement.

#### Ranged IPAM & Multi-Mode Networking
- **Ranged Multi-Mode IPv4 Subnet Pools:** Support for Routed (L3 point-to-point Netplan /32), Bridged (L2 Linux or Incus bridge), Macvlan (L2 direct NIC), and Manual networking modes (IPv6 pools are planned for a future release).
- **IP Pool Address Inspector:** Paginated inspection of usable IPv4 ranges by state (all, free, allocated, cooling, excluded) with O(page) gap-jumping over large ranges.
- **IP Lifecycle & Quarantine:** Side-effect-free IP allocation listings ordered numerically by IPv4, cooldown quarantine window with background scheduler pruning (ipam:prune-cooling), and preflight hypervisor network verification.
- **Hardened L2/L3 Pool Validation & Mutability:** Strict `parent_interface` enforcement (required for `routed`, `bridged`, and `macvlan`; nullable for `manual`), mandatory `gateway` and `/16`–`/30` prefix for L2 modes with preflight gateway/interface-type checks, and mutable `parent_interface` on pools with existing allocations (for newly created or rebuilt instances).
- **IPAM API Contracts, Indexing & Search:** Validated `GET /api/ips` filters (`ListIpAllocationsRequest`), canonical `meta` pagination (`MetaPaginatedResponse<T>`) across IPAM endpoints, SQL-aggregated pool utilization with composite indexes (`idx_ip_alloc_pool_status`, `idx_ip_alloc_user_status`), and prefix/substring-classified allocation search.

#### Multi-Tenant Quotas, Identity & Access Control
- **Reusable Quota Groups:** Tiered resource plans (vCPU, RAM, disk, instances, public IPs, snapshots, including unlimited tiers) with instant propagation to all assigned tenants and hypervisor projects.
- **Hardened Authentication & Secret Rotation:** Per-user Base62 salt + HMAC password pre-hashing, expiring Sanctum Bearer tokens, personal API credentials, email verification, password reset, `APP_KEY` secret re-encryption (`php artisan app:reencrypt-secrets`), and audited admin impersonation.
- **Automated Tenant Lifecycle Jobs:** Background suspension (`SuspendUserWorkloadsJob`) and cleanup (`DeleteUserWorkloadsJob`) of tenant workloads upon account status changes.

#### Tamper-Evident Audit Subsystem
- **Dual-Connection Transactional Auditing:** Fail-closed transactional logging for successful mutations and dedicated out-of-band connection logging for denied/failed attempts.
- **Privilege Separation & Cryptographic Seals:** Production MySQL `INSERT`/`SELECT`-only grants (`AUDIT_STRICT_GRANTS=true`), periodic SHA-256 hash-chain + HMAC sealing (`php artisan audit:seal` / `audit:verify`), and GDPR-compliant PII pseudonymisation (`php artisan audit:pseudonymise`).

#### Real-Time Dashboard & Turnkey Operations
- **Reactive Next.js 16 Interface:** Live status updates via Laravel Reverb WebSockets, bilingual localization (**English** & **Bulgarian**), **Dark / Light / System** themes, and runtime SMTP testing.
- **Frontend Performance & Reactive Navigation:** Active tab derived reactively from pathname, table row memoization (VmTable and IpTable), dynamic modal imports, and accurate global sidebar counts from admin cluster statistics.
- **Networking & Pool Inspection UI:** Distinct pool mode badges, normalized `allocated`/`cooling` status filters, `PoolDetailModal` address browser, **Operator-managed** badges for manual-pool VMs, bilingual English/Bulgarian pool mode descriptions, and single-shot scheduled refresh on cooling IP expiry.
- **Turnkey Docker Topologies:** One-command development (`make up-dev`) and hardened HTTPS production (`make up-prod`) stacks, OpenAPI v3 documentation (`/docs/api`), and automated pre-deployment checks (`php artisan app:check-production-config`).
- **Networking Documentation & Test Guides:** Added [`docs/features/networking.md`](docs/features/networking.md) covering `routed`, `bridged`, `macvlan`, and `manual` modes alongside [`docs/testing/manual-incus-network-tests.md`](docs/testing/manual-incus-network-tests.md) with step-by-step verification checklists.

### Roadmap to `1.0.0` Stable
- **Alpha Phase (`1.0.0-alpha.x`):** Stress testing with multiple independent Incus/LXD compute nodes, edge-case bug fixes, instance access provisioning, and operator UX refinements.
- **Beta Phase (`1.0.0-beta.x`):** API and database schema freeze, upgrade migration path validation, and external security/audit verification.
- **Release Candidate (`1.0.0-rc.x`) & GA (`1.0.0`):** Final stability sign-off for production deployments.