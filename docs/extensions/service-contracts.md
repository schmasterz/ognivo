# Service Contracts

Ognivo Core defines **6 pluggable PHP interfaces** in `backend/app/Contracts/` to decouple infrastructure orchestration from external DNS, notification, billing, backup, provisioning authorization, and plan-change authorization providers.

Extensions provide custom behavior by binding implementations of these contracts in Laravel's service container without modifying any core file.

---

## Core Service Interfaces

### 1. `DnsProviderInterface`

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> Provided as an architectural preview. Subject to refinement in minor `1.x` releases based on feedback from the first production reference implementations.

Contract: `App\Contracts\DnsProviderInterface` (`backend/app/Contracts/DnsProviderInterface.php`)

Manages DNS records in external zones:

```php
interface DnsProviderInterface
{
    public function createRecord(
        string $zone,
        string $name,
        string $type,
        string $content,
        int $ttl = 300,
    ): bool;

    public function deleteRecord(string $zone, string $recordId): bool;

    /**
     * @return list<array{id: string, name: string, type: string, content: string, ttl: int}>
     */
    public function listRecords(string $zone): array;
}
```

### 2. `NotificationChannelInterface`

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> Provided as an architectural preview. Subject to refinement in minor `1.x` releases based on feedback from the first production reference implementations.

Contract: `App\Contracts\NotificationChannelInterface` (`backend/app/Contracts/NotificationChannelInterface.php`)

Delivers operational or tenant notifications over pluggable channels (e.g. email, chat webhooks, SMS):

```php
interface NotificationChannelInterface
{
    public function getIdentifier(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function send(
        string $recipient,
        string $title,
        string $message,
        array $metadata = [],
    ): bool;
}
```

### 3. `BillingProviderInterface`

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> Provided as an architectural preview. Subject to refinement in minor `1.x` releases based on feedback from the first production reference implementations.

Contract: `App\Contracts\BillingProviderInterface` (`backend/app/Contracts/BillingProviderInterface.php`)

Records resource usage measurements for tenants without introducing billing logic into core:

```php
interface BillingProviderInterface
{
    public function getIdentifier(): string;

    /**
     * @param  array<string, mixed>  $tags
     */
    public function recordUsage(
        int $userId,
        string $metric,
        float $quantity,
        array $tags = [],
    ): void;
}
```

### 4. `BackupTargetInterface`

> [!NOTE]
> **Status: Stable / Frozen (SemVer 1.0.0)**  
> Actively utilized by core snapshot orchestration (`VmSnapshotService`, jobs). Governed by strict SemVer 1.0 backward compatibility guarantees.

Contract: `App\Contracts\BackupTargetInterface` (`backend/app/Contracts/BackupTargetInterface.php`)

Decouples virtual machine snapshot and backup storage from hypervisor drivers (`App\Services\VmSnapshotService` resolves this contract):

```php
interface BackupTargetInterface
{
    public function create(VirtualMachine $vm, string $name): bool;

    public function restore(VirtualMachine $vm, string $name): bool;

    public function delete(VirtualMachine $vm, string $name): bool;

    /**
     * @return list<array{name: string, created_at: string, size: int|null}>
     */
    public function list(VirtualMachine $vm): array;
}
```

### 5. `VpsPlanChangeAuthorizerInterface`

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> Provided as an architectural preview. Subject to refinement in minor `1.x` releases based on feedback from the first production reference implementations.

Contract: `App\Contracts\VpsPlanChangeAuthorizerInterface` (`backend/app/Contracts/VpsPlanChangeAuthorizerInterface.php`)

Allows an external billing or approval module to allow, deny, or pause (`pending` -> `awaiting_authorization`) a VPS plan-change request before database locks or hypervisor mutations occur:

```php
interface VpsPlanChangeAuthorizerInterface
{
    public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult;
}
```

### 6. `VpsProvisioningAuthorizerInterface`

> [!NOTE]
> **Status: Developer Preview (`@experimental`)**  
> Provided as an architectural preview. Subject to refinement in minor `1.x` releases based on feedback from the first production reference implementations.

Contract: `App\Contracts\VpsProvisioningAuthorizerInterface` (`backend/app/Contracts/VpsProvisioningAuthorizerInterface.php`)

Allows an external module to authorize or deny instance provisioning requests (with or without a `vps_plan_id`):

```php
interface VpsProvisioningAuthorizerInterface
{
    public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult;
}
```

### Authorization Payloads & Execution Model (`@experimental`)

Payloads are located in `backend/app/Contracts/Data/` (`App\Contracts\Data\*`), and the authorization status enum is located in `backend/app/Enums/Vps/VpsAuthorizationStatus.php` (`App\Enums\Vps\VpsAuthorizationStatus`):

| Class / Enum | Namespace | Properties / Cases |
| :--- | :--- | :--- |
| `VpsAuthorizationStatus` | `App\Enums\Vps\VpsAuthorizationStatus` | `Allowed = 'allowed'`, `Denied = 'denied'`, `Pending = 'pending'` |
| `VpsAuthorizationInitiator` | `App\Contracts\Data\VpsAuthorizationInitiator` | `int $id`, `bool $isAdmin` |
| `VpsPlanChangeAuthorizationRequest` | `App\Contracts\Data\VpsPlanChangeAuthorizationRequest` | `string $requestId` (ULID `public_id`), `int $userId`, `int $virtualMachineId`, `?int $fromVersionId`, `int $toVersionId`, `array $oldResources`, `array $newResources`, `VpsAuthorizationInitiator $initiator` |
| `VpsPlanChangeAuthorizationResult` | `App\Contracts\Data\VpsPlanChangeAuthorizationResult` | `VpsAuthorizationStatus $status`, `?string $reference = null`, `?string $reasonCode = null`, `?string $safeMessage = null` |
| `VpsProvisioningAuthorizationRequest` | `App\Contracts\Data\VpsProvisioningAuthorizationRequest` | `string $requestId`, `int $userId`, `int $toVersionId`, `VpsAuthorizationInitiator $initiator`, `?int $virtualMachineId = null`, `?int $fromVersionId = null`, `array $oldResources = []`, `array $newResources = []` |
| `VpsProvisioningAuthorizationResult` | `App\Contracts\Data\VpsProvisioningAuthorizationResult` | `VpsAuthorizationStatus $status`, `?string $reference = null`, `?string $reasonCode = null`, `?string $safeMessage = null` |

- **Outside-transaction invocation & timeout:** Both `VmProvisioningService::authorizeProvisioning()` and `VpsPlanChangeService::authorizePlanChange()` invoke the authorizer **outside** any `DB::transaction()` or Redis/database row lock so slow external HTTP calls never stall MySQL locks. Execution is bounded by `config('vps.authorizer_timeout_seconds')` (`VPS_AUTHORIZER_TIMEOUT_SECONDS`, default `3` seconds) using `pcntl_alarm` and an `hrtime` wall-clock check; timeouts or thrown exceptions fail closed with HTTP `503` (`EXTENSION_AUTHORIZATION_UNAVAILABLE`).
- **Asynchronous approval (`VpsPlanChangeService::resumeAuthorization()`):** When `VpsPlanChangeAuthorizerInterface::authorize()` returns `VpsAuthorizationStatus::Pending`, `VpsPlanChangeService::request()` persists the plan change in `awaiting_authorization` (`stage = 'authorization'`). Once external approval or payment settles, the extension calls:
  ```php
  app(VpsPlanChangeService::class)->resumeAuthorization(
      publicId: $publicId,
      result: new VpsPlanChangeAuthorizationResult(
          status: VpsAuthorizationStatus::Allowed,
          reference: 'inv_12345',
      ),
  );
  ```
  `resumeAuthorization()` acquires a `lockForUpdate()` CAS lock on the `VpsPlanChange` row (`404` if missing, `409` if no longer in `awaiting_authorization`):
  - `Allowed`: verifies the VM is still `running` or `stopped`, transitions `awaiting_authorization` → `pending`, and dispatches `ApplyVpsPlanChangeJob` (`afterCommit()`).
  - `Denied`: transitions to `failed` (`stage = 'authorization'`) with `reasonCode` / `safeMessage` (defaults to `EXTENSION_AUTHORIZATION_DENIED`).
  - `Pending`: updates `authorization_ref` if provided and leaves the change in `awaiting_authorization`.

---

## Binding Custom Implementations

Bind your implementation inside your extension's `ServiceProvider::register()` method. Because `ExtensionServiceProvider` guards each default binding with `! $this->app->bound(...)` (and because `$this->app->bind(...)` / `$this->app->singleton(...)` updates the container binding cleanly), your extension overrides the default implementation without editing core files:

```php
<?php

declare(strict_types=1);

namespace Acme\DnsSync;

use Acme\DnsSync\Services\PowerDnsProvider;
use App\Contracts\DnsProviderInterface;
use Illuminate\Support\ServiceProvider;
use Override;

final class DnsSyncServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(DnsProviderInterface::class, static function (): DnsProviderInterface {
            return new PowerDnsProvider(
                endpoint: (string) config('dns_sync.endpoint'),
                apiKey: (string) config('dns_sync.api_key'),
            );
        });
    }
}
```

Declare your provider in `ognivo-extension.json`:

```json
{
  "name": "acme/dns-sync",
  "version": "1.0.0",
  "ognivo_version_constraint": "^1.0",
  "providers": [
    "Acme\\DnsSync\\DnsSyncServiceProvider"
  ]
}
```

When core services or other extensions inject `DnsProviderInterface` via constructor dependency injection or `app(DnsProviderInterface::class)`, Laravel resolves `PowerDnsProvider`.

---

## Default Fallback Bindings (`ExtensionServiceProvider`)

`App\Providers\ExtensionServiceProvider` (`backend/app/Providers/ExtensionServiceProvider.php`) is registered in `backend/bootstrap/providers.php` and ensures that every core contract resolves cleanly out of the box even when no external extension is installed:

| Contract | Default Implementation | Default Behavior |
| --- | --- | --- |
| `DnsProviderInterface` | Anonymous null implementation | `createRecord()` and `deleteRecord()` return `false`; `listRecords()` returns `[]`. |
| `NotificationChannelInterface` | Anonymous log implementation | `getIdentifier()` returns `'log'`; `isConfigured()` returns `true`; `send()` writes an info entry via `Log::info(...)` and returns `true`. |
| `BillingProviderInterface` | Anonymous null implementation | `getIdentifier()` returns `'null'`; `recordUsage()` is a no-op. |
| `BackupTargetInterface` | `App\Services\Backup\LocalSnapshotTarget` | Delegates `create`, `restore`, `delete`, and `list` to the VM's hypervisor driver via `HypervisorManager::driverForVm()`. |
| `VpsPlanChangeAuthorizerInterface` | `App\Services\Vps\AllowAllVpsPlanChangeAuthorizer` | Unconditionally returns `VpsAuthorizationStatus::Allowed`. |
| `VpsProvisioningAuthorizerInterface` | `App\Services\Vps\AllowAllVpsProvisioningAuthorizer` | Unconditionally returns `VpsAuthorizationStatus::Allowed`. |

`ExtensionServiceProvider::register()` only binds a fallback when `! $this->app->bound(ContractInterface::class)` is true, ensuring any earlier container binding is never overwritten.
