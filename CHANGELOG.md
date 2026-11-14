# Changelog

All notable changes to **Ognivo** are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0-alpha.1] - 2026-11-14

> **Pre-Release Notice (Alpha):**
> `v1.0.0-alpha.1` is the first public alpha preview of the **Ognivo 1.0.0** control plane. All core `1.0.0` subsystems are implemented and available for evaluation, staging deployments, and community feedback. While comprehensive automated test suites and security controls are in place, database schemas, internal APIs, and configuration keys may still evolve prior to the final `1.0.0` General Availability (GA) release. **Do not use in mission-critical production without external backups.**

### 🎯 Vision for `1.0.0`

**Ognivo 1.0.0** establishes a modern, security-first open-source multi-tenant cloud control plane for **Incus** and **LXD** hypervisors, built with **Laravel 13 (PHP 8.5)**, **Next.js 16 (React 19)**, **Laravel Reverb**, and an isolated **Node.js 26 LTS mTLS Console Proxy**.

### ✨ Added in `1.0.0-alpha.1`

#### 🖥️ Compute & Workload Orchestration
- **KVM Virtual Machines & LXC Containers:** Unified provisioning and lifecycle state machine (`Provisioning`, `Running`, `Stopped`, `Suspended`, `Deleting`, `Deleted`, `Failed`).
- **VM Reinstall:** Non-destructive operating system reinstallation (`reinstall` lifecycle action with IP preservation and confirmation UI).
- **Atomic Provisioning & Compensation:** Race-free resource reservation using database row locks (`lockForUpdate`) and Redis mutexes, backed by automatic compensating rollbacks (`ProvisioningCompensator`) if hypervisor creation fails.
- **Per-Tenant Hypervisor Isolation:** Automatic provisioning and quota synchronization of dedicated Incus/LXD projects (`proj-{uuid}`) per tenant.
- **Cloud-Init & Image Catalog:** Curated OS image management with automated Cloud-Init user/password, SSH key, and Netplan network injection.

#### 🔐 Cryptographic Hypervisor Management & Drift Reconciliation
- **mTLS Onboarding & Trust Pinning:** Automated `secp384r1` X.509 client certificate generation, one-time trust token onboarding, SPKI public-key pinning (`CURLOPT_PINNEDPUBLICKEY` / `sha256//...` TOFU + re-trust workflow), and zero-downtime certificate rotation.
- **Host Capacity Discovery & Enforcement:** Automated sync of hypervisor CPU, memory, and disk resources (`php artisan hosts:sync-resources`), intelligent placement (`HostSelector`), and preflight overcommit checks (`HostCapacityGuard`).
- **Multi-Driver Architecture:** Pluggable `HypervisorDriverInterface` supporting `IncusDriver`, `LxdDriver`, and `FakeHypervisorDriver` for deterministic testing.
- **Live Event Streaming & Drift Detection:** Real-time hypervisor event daemon (`php artisan incus:listen`) combined with scheduled state reconciliation (`php artisan vm:reconcile`) and an administrative Drift Resolution UI.

#### 🖥️ Zero-Knowledge Interactive Web Console
- **Isolated `console-proxy` Microservice:** Standalone Node.js 26 LTS service streaming live `xterm.js` terminal sessions and console logs over mTLS WebSockets using atomic single-use 60-second Redis tickets (`GETDEL`).
- **Strict Tenant Console Privacy:** Cryptographic and policy-level guardrails (`VirtualMachinePolicy::viewConsole`) prevent administrators—even during active user impersonation—from accessing customer guest consoles.

#### 📸 Point-in-Time Snapshots
- **Stateful & Stateless Snapshots:** Asynchronous snapshot creation, restoration, and deletion with per-VM concurrency locks and real-time WebSocket progress transitions.
- **Pluggable Backup Architecture:** Built around `BackupTargetInterface` with local hypervisor snapshot support, per-VM and global snapshot management views, and tier quota enforcement.

#### 🌐 Ranged IPAM & Multi-Mode Networking
- **IPv4 Subnet Pools:** Support for both **Routed** (Layer 3 `/32` point-to-point with Cloud-Init Netplan) and **Manual** networking modes (IPv6 pools are planned for a future release).
- **IP Lifecycle & Preflight Verification:** Dedicated IP allocation lifecycle, allocation cooldown quarantine windows, and automated hypervisor network preflight checks.

#### 📊 Multi-Tenant Quotas, Identity & Access Control
- **Reusable Quota Groups:** Tiered resource plans (vCPU, RAM, disk, instances, public IPs, snapshots, including unlimited `∞` tiers) with instant propagation to all assigned tenants and hypervisor projects.
- **Hardened Authentication & Secret Rotation:** Per-user Base62 salt + HMAC password pre-hashing, expiring Sanctum Bearer tokens, personal API credentials, email verification, password reset, `APP_KEY` secret re-encryption (`php artisan app:reencrypt-secrets`), and audited admin impersonation.
- **Automated Tenant Lifecycle Jobs:** Background suspension (`SuspendUserWorkloadsJob`) and cleanup (`DeleteUserWorkloadsJob`) of tenant workloads upon account status changes.

#### 🛡️ Tamper-Evident Audit Subsystem
- **Dual-Connection Transactional Auditing:** Fail-closed transactional logging for successful mutations and dedicated out-of-band connection logging for denied/failed attempts.
- **Privilege Separation & Cryptographic Seals:** Production MySQL `INSERT`/`SELECT`-only grants (`AUDIT_STRICT_GRANTS=true`), periodic SHA-256 hash-chain + HMAC sealing (`php artisan audit:seal` / `audit:verify`), and GDPR-compliant PII pseudonymisation (`php artisan audit:pseudonymise`).

#### ⚡ Real-Time Dashboard & Turnkey Operations
- **Reactive Next.js 16 Interface:** Live status updates via Laravel Reverb WebSockets, bilingual localization (**English** & **Bulgarian**), **Dark / Light / System** themes, and runtime SMTP testing.
- **Turnkey Docker Topologies:** One-command development (`make up-dev`) and hardened HTTPS production (`make up-prod`) stacks, OpenAPI v3 documentation (`/docs/api`), and automated pre-deployment checks (`php artisan app:check-production-config`).

### 🗺️ Roadmap to `1.0.0` Stable
- **Alpha Phase (`1.0.0-alpha.x`):** Multi-node Incus/LXD cluster stress testing, edge-case bug fixes, and operator UX refinements.
- **Beta Phase (`1.0.0-beta.x`):** API and database schema freeze, upgrade migration path validation, and external security/audit verification.
- **Release Candidate (`1.0.0-rc.x`) & GA (`1.0.0`):** Final stability sign-off for production deployments.
