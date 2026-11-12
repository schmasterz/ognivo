# Backend Agent Guide

## 1. Tech Stack & Runtime
- **Language & Framework:** PHP `8.5` (`declare(strict_types=1)` across all files), Laravel `13`
- **Authentication & RBAC:** Laravel Sanctum `4` (SPA cookie + API tokens), `spatie/laravel-permission` (`admin` / `customer` roles), tenant impersonation middleware
- **Routing & OpenAPI:** `spatie/laravel-route-attributes` (PHP 8 attributes on Controllers), Dedoc Scramble (`/docs/api`)
- **State Machine & Async:** `spatie/laravel-model-states` (`VmState`), Redis queues/locks (`predis`), Laravel Reverb `1.12` WebSockets
- **Static Analysis & Testing:** Larastan / PHPStan (**Level 10**), Vimeo Psalm (**ErrorLevel 1**), Pest PHP `5` / PHPUnit `13` (SQLite unit/feature suite + MySQL 8.4/Redis 7 integration suite)

---

## 2. Routing & Request Pipeline Architecture

API routes are **never** defined in `routes/api.php`. All HTTP endpoints are registered via PHP 8 attributes (`#[Get]`, `#[Post]`, `#[Put]`, `#[Patch]`, `#[Delete]`, `#[Prefix]`, `#[Middleware]`) directly on Controller classes under `app/Http/Controllers/` (auto-prefixed with `/api` via `config/route-attributes.php`).

```text
HTTP Request (/api/*)
  └── Middleware Pipeline (SetLocale -> Sanctum Auth -> Active User -> Impersonation -> AuditContext)
        └── FormRequest (app/Http/Requests/**) -> Validation & Authorization
              └── Controller (app/Http/Controllers/**) -> Maps FormRequest to readonly DTO
                    └── Domain Service (app/Services/**) -> Business logic, DB transactions, Redis locks, AuditLogger
                          └── JsonResource (app/Http/Resources/**) -> Standardized API response
```

### Routing & Controller Rules
- **Attribute-Only Routing:** Keep `routes/api.php` empty. Declare routes with `Spatie\RouteAttributes\Attributes\*` on `final` controller classes.
- **Thin Controllers:** Controllers must only authorize requests, convert validated input into a typed `readonly` DTO (`app/DTO/**`), invoke a domain Service, and return an Eloquent `JsonResource` or `JsonResponse`.
- **Tenant vs. Admin Separation:**
  - Admin-only controllers live in `app/Http/Controllers/Admin/` and must enforce `EnsureUserIsAdmin` (`admin` middleware).
  - Never expose admin fields (e.g., hypervisor internal endpoints, tokens, audit HMAC metadata, or cross-tenant records) in tenant resources; use dedicated resource classes (`TenantHostResource` vs. `HostNodeResource`, `AuditEntryResource` vs. `AdminAuditEntryResource`).

---

## 3. Directory & Domain Organization (`app/`)

```text
app/
├── Audit/                            # Tamper-evident audit subsystem (AuditLogger, AuditContext, AuditAction, AuditRedactor)
├── Console/Commands/                 # Artisan CLI commands (incus:listen, audit:*, admin:create, app:check-production-config)
├── Contracts/                        # Core interfaces (BackupTargetInterface)
├── DTO/                              # Immutable readonly Data Transfer Objects grouped by domain (Vm, Host, Ip, Quota, User, Auth, Audit)
├── Events/                           # Reverb broadcast events (VirtualMachineStatusUpdated, SnapshotStatusUpdated, etc.)
├── Exceptions/                       # Domain & HTTP exceptions with translated rendering
├── Http/
│   ├── Controllers/                  # Tenant controllers & Admin/ controllers (route-attribute driven)
│   ├── Middleware/                   # SetAuditContext, SetImpersonationContext, ForbidImpersonationActions, EnsureUserIsAdmin, SetLocaleMiddleware
│   ├── Requests/                     # FormRequest validation classes grouped by domain
│   └── Resources/                    # API serialization resources (strictly separating tenant vs. admin views)
├── Jobs/                             # Queued async jobs (ProvisionVmJob, CreateVmSnapshotJob, SealAuditLogsJob, etc.) with RestoreAuditContext
├── Listeners/                        # Event subscribers (AuthAuditSubscriber)
├── Mail/ & Notifications/            # Queued transactional emails & alerts
├── Models/                           # Eloquent models (VirtualMachine, Host, IpPool, IpAllocation, VmSnapshot, AuditLog, AuditSeal, etc.)
├── Policies/                         # Authorization policies (VirtualMachinePolicy — enforces strict console privacy)
├── Services/
│   ├── Hypervisor/                   # HypervisorGateway, HypervisorManager, Drivers (Incus, Lxd, Fake), mTLS & fingerprint pinning
│   ├── Network/                      # CidrHelper & ranged IPAM utilities
│   ├── Backup/                       # Pluggable snapshot backup targets (LocalSnapshotTarget)
│   ├── VmProvisioningService.php     # Atomic quota + IP reservation & job dispatch (+ ProvisioningCompensator)
│   ├── VmLifecycleService.php        # Start, stop, reboot, suspend, delete state transitions
│   ├── VmSnapshotService.php         # Snapshot lifecycle with distributed Redis locks
│   ├── ConsoleTicketService.php      # 60s single-use Redis console tickets (tenant-only)
│   ├── IpManagementService.php       # Ranged IPAM, floating IPs, PTR validation, row-level locking
│   ├── PanelCertificateService.php   # secp384r1 X.509 mTLS certificate generation & zero-downtime rotation
│   ├── IncusTrustService.php         # One-time trust token onboarding & peer TLS fingerprint verification
│   └── AuditSealService.php          # Periodic SHA-256 hash-chain + HMAC sealing & verification
└── States/Vm/                        # Spatie state machine (Provisioning, Running, Stopped, Suspended, Deleting, Deleted, Failed)
```

---

## 4. Coding Conventions & Strict Guardrails

1. **Language & I18n:**
   - All code, identifiers, comments, docstrings, commit messages, and documentation MUST be in **English**.
   - User-facing API error/status messages must use Laravel translation keys (`__('messages.key')`) with entries in both `lang/en/` and `lang/bg/`.
2. **Strict Typing (PHP 8.5 + PHPStan Level 10 + Psalm Level 1):**
   - Every `.php` file MUST start with `declare(strict_types=1);`.
   - Prefer `final` classes and `readonly` DTOs.
   - Add explicit parameter, return, and generic PHPDoc types (`@return BelongsTo<User, $this>`, `array<string, mixed>`, `list<string>`) so both PHPStan Level 10 and Psalm ErrorLevel 1 pass without adding new baseline suppressions.
3. **Hypervisor & Security Guardrails:**
   - **Never** bypass `HypervisorGateway` when communicating with Incus/LXD nodes. All remote calls must enforce mTLS client certificates and pinned server SHA-256 certificate fingerprints.
   - **Strict Tenant Console Privacy:** Administrators (including during tenant impersonation or via `ForbidImpersonationActions`) are cryptographically and policy-blocked (`VirtualMachinePolicy::console`) from generating console tickets for customer VMs. Never weaken this check.
   - **Encryption:** Sensitive model attributes (`PanelCertificate::private_key`, `Host::token`) must use Laravel's `encrypted` cast and remain in `$hidden`.
4. **Concurrency & Atomic Resource Locking:**
   - Quota reservations (`UserQuota`) and IP allocations (`IpAllocation`) MUST execute inside `DB::transaction()` using `lockForUpdate()` (and Redis locks `Cache::lock(...)` where applicable) to prevent race conditions.
   - Multi-step async provisioning failures must roll back reserved resources via `ProvisioningCompensator`.
5. **Tamper-Evident Audit Logging:**
   - All state-mutating or security-sensitive operations must record an `AuditEntry` via `AuditLogger`.
   - Queued jobs dispatched from HTTP requests must use the `RestoreAuditContext` trait so actor, impersonator, IP, and request correlation IDs propagate into background workers.
   - Respect the dual-connection model (`audit` connection for `INSERT`/`SELECT`, `audit_maintenance` for scheduled pruning/pseudonymisation). Never issue `UPDATE` or `DELETE` queries on `AuditLog` from application code.

---

## 5. Verification Commands (Must Pass Before Every Commit)

Run inside the backend container (`docker exec ognivo-backend-dev <cmd>`) or from `backend/`:

```bash
# 1. PHPStan Level 10 Static Analysis (must report 0 errors)
vendor/bin/phpstan analyse --memory-limit=1G

# 2. Vimeo Psalm ErrorLevel 1 Static Analysis (must report 0 errors)
vendor/bin/psalm --no-cache

# 3. Fast Unit & Feature Test Suite (SQLite in-memory)
php artisan test --exclude-group=integration

# 4. Real MySQL 8.4 & Redis 7 Integration Suite (when modifying locks, IPAM, queues, or audit grants)
# (Requires `docker compose -f docker-compose.test.yml up -d` on the host)
vendor/bin/pest --configuration=phpunit.integration.xml --group=integration
```
