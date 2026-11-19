# Changelog

All notable changes to **Ognivo** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **IPAM Pool Utilization & Numeric Ordering:** Pool utilization is now computed via SQL aggregates instead of loading allocations in application code, IP allocation listings are ordered by pool and then numerically by IPv4 address, and `ip_allocations` adds composite indexes `idx_ip_alloc_pool_status` and `idx_ip_alloc_user_status`.
- **UI Cooling IP Refresh:** Cooling IP entries in the UI now use a single scheduled refresh when the cooldown expires instead of polling every 5 seconds.
- **Strict `parent_interface` Validation:** `parent_interface` no longer silently defaults to `eth0`; it is required for all non-manual pools (`routed`, `bridged`, `macvlan`), while `manual` pools store an empty string and return `null` in API responses.
- **Mutable `parent_interface` on Allocated Pools:** `parent_interface` can now be changed on IP pools that already have allocations (applies only to newly created or rebuilt instances, not already running ones); `cidr`, `host_id`, and `network_mode` remain immutable while allocations exist.
- **Classified IP Allocations Search:** The allocations `search` filter now matches IPv4 terms and MAC addresses by prefix (`term%`) and non-IP/non-MAC text (user name/email, VM name, pool name) by substring (`%term%`) with escaped `LIKE` wildcards.

### Fixed
- **Side-Effect-Free `GET /ips`:** `GET /ips` no longer deletes expired `cooling` allocation rows on read; expired rows are removed exclusively by the scheduled `ipam:prune-cooling` command and are reported as `released` with `cooldown_remaining_seconds` `0` until pruned.

## [1.0.0-alpha.1] - 2026-11-14

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
- **Stateful & Stateless Snapshots:** Asynchronous snapshot creation, restoration, and deletion with per-VM concurrency locks and real-time WebSocket progress transitions.
- **Pluggable Backup Architecture:** Built around `BackupTargetInterface` with local hypervisor snapshot support, per-VM and global snapshot management views, and tier quota enforcement.

#### Ranged IPAM & Multi-Mode Networking
- **IPv4 Subnet Pools:** Support for both **Routed** (Layer 3 `/32` point-to-point with Cloud-Init Netplan) and **Manual** networking modes (IPv6 pools are planned for a future release).
- **IP Lifecycle & Preflight Verification:** Dedicated IP allocation lifecycle, allocation cooldown quarantine windows, and automated hypervisor network preflight checks.

#### Multi-Tenant Quotas, Identity & Access Control
- **Reusable Quota Groups:** Tiered resource plans (vCPU, RAM, disk, instances, public IPs, snapshots, including unlimited tiers) with instant propagation to all assigned tenants and hypervisor projects.
- **Hardened Authentication & Secret Rotation:** Per-user Base62 salt + HMAC password pre-hashing, expiring Sanctum Bearer tokens, personal API credentials, email verification, password reset, `APP_KEY` secret re-encryption (`php artisan app:reencrypt-secrets`), and audited admin impersonation.
- **Automated Tenant Lifecycle Jobs:** Background suspension (`SuspendUserWorkloadsJob`) and cleanup (`DeleteUserWorkloadsJob`) of tenant workloads upon account status changes.

#### Tamper-Evident Audit Subsystem
- **Dual-Connection Transactional Auditing:** Fail-closed transactional logging for successful mutations and dedicated out-of-band connection logging for denied/failed attempts.
- **Privilege Separation & Cryptographic Seals:** Production MySQL `INSERT`/`SELECT`-only grants (`AUDIT_STRICT_GRANTS=true`), periodic SHA-256 hash-chain + HMAC sealing (`php artisan audit:seal` / `audit:verify`), and GDPR-compliant PII pseudonymisation (`php artisan audit:pseudonymise`).

#### Real-Time Dashboard & Turnkey Operations
- **Reactive Next.js 16 Interface:** Live status updates via Laravel Reverb WebSockets, bilingual localization (**English** & **Bulgarian**), **Dark / Light / System** themes, and runtime SMTP testing.
- **Turnkey Docker Topologies:** One-command development (`make up-dev`) and hardened HTTPS production (`make up-prod`) stacks, OpenAPI v3 documentation (`/docs/api`), and automated pre-deployment checks (`php artisan app:check-production-config`).

### Roadmap to `1.0.0` Stable
- **Alpha Phase (`1.0.0-alpha.x`):** Stress testing with multiple independent Incus/LXD compute nodes, edge-case bug fixes, instance access provisioning, and operator UX refinements.
- **Beta Phase (`1.0.0-beta.x`):** API and database schema freeze, upgrade migration path validation, and external security/audit verification.
- **Release Candidate (`1.0.0-rc.x`) & GA (`1.0.0`):** Final stability sign-off for production deployments.