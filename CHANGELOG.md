# Changelog

All notable changes to **Ognivo** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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