<?php

declare(strict_types=1);

use App\Events\Access\MaintenanceWindowScheduled;
use App\Events\Access\ServiceTokenCreated;
use App\Events\Access\UserRoleAssigned;
use App\Events\Contracts\OgnivoEventInterface;
use App\Events\Host\HostMaintenanceEntered;
use App\Events\Host\HostMaintenanceExited;
use App\Events\Host\HostRegistered;
use App\Events\Host\HostStatusUpdated;
use App\Events\Storage\SnapshotCreated;
use App\Events\Storage\VolumeAttached;
use App\Events\Storage\VolumeCreated;
use App\Events\Storage\VolumeDetached;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @return array<string, array{
 *     factory: callable(array{type: string, id: string|null, label: string|null}|null): OgnivoEventInterface,
 *     expectedName: string,
 *     expectedPayload: array<string, mixed>,
 * }>
 */
dataset('remainingDomainEvents', [
    'host.registered' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new HostRegistered(
            hostId: 1,
            name: 'compute-01',
            hostname: 'compute-01.example.com',
            ipAddress: '10.0.0.10',
            actor: $actor,
        ),
        'expectedName' => 'host.registered',
        'expectedPayload' => [
            'host_id' => 1,
            'name' => 'compute-01',
            'hostname' => 'compute-01.example.com',
            'ip_address' => '10.0.0.10',
        ],
    ],
    'host.status_updated' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new HostStatusUpdated(
            hostId: 1,
            oldStatus: 'online',
            newStatus: 'offline',
            actor: $actor,
        ),
        'expectedName' => 'host.status_updated',
        'expectedPayload' => [
            'host_id' => 1,
            'old_status' => 'online',
            'new_status' => 'offline',
        ],
    ],
    'host.maintenance_entered' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new HostMaintenanceEntered(
            hostId: 1,
            reason: 'Kernel security upgrade',
            actor: $actor,
        ),
        'expectedName' => 'host.maintenance_entered',
        'expectedPayload' => [
            'host_id' => 1,
            'reason' => 'Kernel security upgrade',
        ],
    ],
    'host.maintenance_exited' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new HostMaintenanceExited(
            hostId: 1,
            actor: $actor,
        ),
        'expectedName' => 'host.maintenance_exited',
        'expectedPayload' => [
            'host_id' => 1,
        ],
    ],
    'storage.volume_created' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new VolumeCreated(
            volumeId: 10,
            name: 'data-vol-01',
            sizeGb: 100,
            pool: 'zfs-nvme',
            actor: $actor,
        ),
        'expectedName' => 'storage.volume_created',
        'expectedPayload' => [
            'volume_id' => 10,
            'name' => 'data-vol-01',
            'size_gb' => 100,
            'pool' => 'zfs-nvme',
        ],
    ],
    'storage.volume_attached' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new VolumeAttached(
            volumeId: 10,
            instanceId: 200,
            devicePath: '/dev/sdb',
            actor: $actor,
        ),
        'expectedName' => 'storage.volume_attached',
        'expectedPayload' => [
            'volume_id' => 10,
            'instance_id' => 200,
            'device_path' => '/dev/sdb',
        ],
    ],
    'storage.volume_detached' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new VolumeDetached(
            volumeId: 10,
            instanceId: 200,
            actor: $actor,
        ),
        'expectedName' => 'storage.volume_detached',
        'expectedPayload' => [
            'volume_id' => 10,
            'instance_id' => 200,
        ],
    ],
    'storage.snapshot_created' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new SnapshotCreated(
            snapshotId: 30,
            instanceId: 200,
            name: 'pre-upgrade-snap',
            actor: $actor,
        ),
        'expectedName' => 'storage.snapshot_created',
        'expectedPayload' => [
            'snapshot_id' => 30,
            'instance_id' => 200,
            'name' => 'pre-upgrade-snap',
        ],
    ],
    'access.user_role_assigned' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new UserRoleAssigned(
            userId: 42,
            role: 'admin',
            actor: $actor,
        ),
        'expectedName' => 'access.user_role_assigned',
        'expectedPayload' => [
            'user_id' => 42,
            'role' => 'admin',
        ],
    ],
    'access.service_token_created' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new ServiceTokenCreated(
            tokenId: 77,
            name: 'ci-deploy',
            abilities: ['instances:read', 'instances:write'],
            actor: $actor,
        ),
        'expectedName' => 'access.service_token_created',
        'expectedPayload' => [
            'token_id' => 77,
            'name' => 'ci-deploy',
            'abilities' => ['instances:read', 'instances:write'],
        ],
    ],
    'system.maintenance_window_scheduled' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new MaintenanceWindowScheduled(
            title: 'Network Switch Firmware Update',
            startsAt: '2026-12-01T02:00:00Z',
            endsAt: '2026-12-01T04:00:00Z',
            actor: $actor,
        ),
        'expectedName' => 'system.maintenance_window_scheduled',
        'expectedPayload' => [
            'title' => 'Network Switch Firmware Update',
            'starts_at' => '2026-12-01T02:00:00Z',
            'ends_at' => '2026-12-01T04:00:00Z',
        ],
    ],
]);

it('implements OgnivoEventInterface and ShouldDispatchAfterCommit with expected name, payload, actor, and UTC timestamp', function (
    callable $factory,
    string $expectedName,
    array $expectedPayload,
): void {
    $actor = [
        'type' => 'user',
        'id' => '42',
        'label' => 'operator@example.com',
    ];

    /** @var OgnivoEventInterface $event */
    $event = $factory($actor);

    expect($event)
        ->toBeInstanceOf(OgnivoEventInterface::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->getEventName())->toBe($expectedName)
        ->and($event->getPayload())->toBe($expectedPayload)
        ->and($event->getActor())->toBe($actor)
        ->and($event->getOccurredAt())->toBeInstanceOf(DateTimeImmutable::class)
        ->and($event->getOccurredAt()->getTimezone()->getName())->toBe('UTC');
})->with('remainingDomainEvents');

it('defaults actor to null and uses default reason on HostMaintenanceEntered', function (): void {
    $event = new HostMaintenanceEntered(hostId: 5);

    expect($event->getActor())->toBeNull()
        ->and($event->getEventName())->toBe('host.maintenance_entered')
        ->and($event->getPayload())->toBe([
            'host_id' => 5,
            'reason' => '',
        ])
        ->and($event->getOccurredAt()->getTimezone()->getName())->toBe('UTC');
});
