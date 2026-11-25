# Domain Events Catalog

Ognivo Core emits **17 strongly typed domain events** covering instance lifecycles, compute hosts, storage operations, access control, and scheduled maintenance. Extensions can listen to these events in-process via Laravel's event dispatcher or out-of-process via [Webhooks](./webhooks-and-tokens.md).

---

## Event Contract & Base Class

All 17 domain events implement `App\Events\Contracts\OgnivoEventInterface` (`backend/app/Events/Contracts/OgnivoEventInterface.php`) and extend `App\Events\AbstractOgnivoEvent` (`backend/app/Events/AbstractOgnivoEvent.php`).

### `OgnivoEventInterface`

| Method | Return Type | Description |
| --- | --- | --- |
| `getEventName()` | `string` | Unique dot-notation event identifier (e.g. `"instance.created"`). |
| `getPayload()` | `array<string, mixed>` | JSON-serializable structured payload for listeners and webhooks. |
| `getOccurredAt()` | `DateTimeImmutable` | Immutable UTC timestamp recorded when the event was constructed. |
| `getActor()` | `?array{type: string, id: string\|null, label: string\|null}` | Contextual initiator (`user`, `system` / service token, or `null`). |

### Actor Resolution

`AbstractOgnivoEvent` resolves `$actor` automatically unless explicitly passed to the constructor:

1. If `App\Audit\AuditContext` is bound and populated with a non-default actor (`actorId !== null`, `actorLabel !== null`, or `actorType !== 'user'`), `$actor` reflects that context (for example `type: "system"`, `label: "service_token:<name>"`).
2. Otherwise, if an authenticated `App\Models\User` is present via `Auth::user()`, `$actor` is populated with `type: "user"`, `id: (string) $user->id`, and `label: $user->email ?: $user->name`.
3. If neither is available (e.g. an unauthenticated CLI process without context), `getActor()` returns `null`.

---

## Transaction Boundary & After-Commit Guarantee

`AbstractOgnivoEvent` implements Laravel's `Illuminate\Contracts\Events\ShouldDispatchAfterCommit`:

```php
abstract class AbstractOgnivoEvent implements OgnivoEventInterface, ShouldDispatchAfterCommit
```

Core services (such as `VmLifecycleService`, `HostNodeService`, and `VmSnapshotService`) dispatch domain events inside `DB::transaction(...)` blocks alongside state mutations and audit log writes. Because of `ShouldDispatchAfterCommit`:

- Listeners and webhook subscribers are **never invoked until the outermost database transaction commits**.
- If an operation fails, throws an exception, or rolls back, the event is **automatically discarded**—preventing phantom notifications, billing entries, or DNS updates.

---

## Listening to Events in an Extension

You can listen to a specific event class or subscribe to `OgnivoEventInterface` to handle all 17 domain events uniformly:

```php
<?php

declare(strict_types=1);

namespace Acme\DnsSync\Listeners;

use App\Events\Contracts\OgnivoEventInterface;
use App\Events\Instance\InstanceCreated;
use App\Events\Instance\InstanceTerminated;
use Illuminate\Contracts\Events\Dispatcher;

final class InstanceDnsSubscriber
{
    public function handleCreated(InstanceCreated $event): void
    {
        $payload = $event->getPayload();
        // $payload['instance_id'], $payload['hostname'], etc.
    }

    public function handleAny(OgnivoEventInterface $event): void
    {
        // Fires only after DB transaction commit
        $name = $event->getEventName();
        $occurredAt = $event->getOccurredAt();
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(InstanceCreated::class, [self::class, 'handleCreated']);
        $events->listen(OgnivoEventInterface::class, [self::class, 'handleAny']);
    }
}
```

Register the subscriber in your extension's `ServiceProvider::boot()` via `Event::subscribe(InstanceDnsSubscriber::class)`.

---

## Complete Catalog of Domain Events (17 Events)

### Instance Events (6)

Namespace: `App\Events\Instance\`

| Event Name | Class | Payload Keys & Types |
| --- | --- | --- |
| `instance.created` | `InstanceCreated` | `instance_id` (`int`), `name` (`string`), `hostname` (`string`), `cpu` (`int`), `memory_mb` (`int`), `disk_gb` (`int`), `image` (`string`) |
| `instance.started` | `InstanceStarted` | `instance_id` (`int`), `name` (`string`), `status` (`string`, default `"running"`) |
| `instance.stopped` | `InstanceStopped` | `instance_id` (`int`), `name` (`string`), `status` (`string`, default `"stopped"`) |
| `instance.rebooted` | `InstanceRebooted` | `instance_id` (`int`), `name` (`string`), `status` (`string`, default `"running"`) |
| `instance.terminated` | `InstanceTerminated` | `instance_id` (`int`), `name` (`string`) |
| `instance.resized` | `InstanceResized` | `instance_id` (`int`), `old_specs` (`array{cpu?: int, memory_mb?: int, disk_gb?: int}`), `new_specs` (`array{cpu?: int, memory_mb?: int, disk_gb?: int}`) |

### Host Events (4)

Namespace: `App\Events\Host\`

| Event Name | Class | Payload Keys & Types |
| --- | --- | --- |
| `host.registered` | `HostRegistered` | `host_id` (`int`), `name` (`string`), `hostname` (`string`), `ip_address` (`string`) |
| `host.status_updated` | `HostStatusUpdated` | `host_id` (`int`), `old_status` (`string`), `new_status` (`string`) |
| `host.maintenance_entered` | `HostMaintenanceEntered` | `host_id` (`int`), `reason` (`string`, default `""`) |
| `host.maintenance_exited` | `HostMaintenanceExited` | `host_id` (`int`) |

### Storage Events (4)

Namespace: `App\Events\Storage\`

| Event Name | Class | Payload Keys & Types |
| --- | --- | --- |
| `storage.volume_created` | `VolumeCreated` | `volume_id` (`int`), `name` (`string`), `size_gb` (`int`), `pool` (`string`) |
| `storage.volume_attached` | `VolumeAttached` | `volume_id` (`int`), `instance_id` (`int`), `device_path` (`string`) |
| `storage.volume_detached` | `VolumeDetached` | `volume_id` (`int`), `instance_id` (`int`) |
| `storage.snapshot_created` | `SnapshotCreated` | `snapshot_id` (`int`), `instance_id` (`int`), `name` (`string`) |

### Access & System Events (3)

Namespace: `App\Events\Access\`

| Event Name | Class | Payload Keys & Types |
| --- | --- | --- |
| `access.user_role_assigned` | `UserRoleAssigned` | `user_id` (`int`), `role` (`string`) |
| `access.service_token_created` | `ServiceTokenCreated` | `token_id` (`int`), `name` (`string`), `abilities` (`array<int, string>`) |
| `system.maintenance_window_scheduled` | `MaintenanceWindowScheduled` | `title` (`string`), `starts_at` (`string`), `ends_at` (`string`) |

> **Note:** `MaintenanceWindowScheduled` resides in the `App\Events\Access` namespace while returning `"system.maintenance_window_scheduled"` from `getEventName()`. Always filter webhooks and listeners by `getEventName()` or the class FQCN.
