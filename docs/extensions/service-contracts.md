# Service Contracts

Ognivo Core defines **4 pluggable PHP interfaces** in `backend/app/Contracts/` to decouple infrastructure orchestration from external DNS, notification, billing, and backup providers.

Extensions provide custom behavior by binding implementations of these contracts in Laravel's service container without modifying any core file.

---

## Core Service Interfaces

### 1. `DnsProviderInterface`

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

`ExtensionServiceProvider::register()` only binds a fallback when `! $this->app->bound(ContractInterface::class)` is true, ensuring any earlier container binding is never overwritten.
