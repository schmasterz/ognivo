<?php

declare(strict_types=1);

use App\Events\AbstractOgnivoEvent;
use App\Events\Access\MaintenanceWindowScheduled;
use App\Events\Access\ServiceTokenCreated;
use App\Events\Access\UserRoleAssigned;
use App\Events\Contracts\OgnivoEventInterface;
use App\Events\Host\HostMaintenanceEntered;
use App\Events\Host\HostMaintenanceExited;
use App\Events\Host\HostRegistered;
use App\Events\Host\HostStatusUpdated;
use App\Events\Instance\InstanceCreated;
use App\Events\Instance\InstanceRebooted;
use App\Events\Instance\InstanceResized;
use App\Events\Instance\InstanceStarted;
use App\Events\Instance\InstanceStopped;
use App\Events\Instance\InstanceTerminated;
use App\Events\Storage\SnapshotCreated;
use App\Events\Storage\VolumeAttached;
use App\Events\Storage\VolumeCreated;
use App\Events\Storage\VolumeDetached;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @return array<class-string<AbstractOgnivoEvent>, callable(): AbstractOgnivoEvent>
 */
function domainEventContractFactories(): array
{
    $actor = [
        'type' => 'system',
        'id' => null,
        'label' => 'contract-test',
    ];

    return [
        // Instance (6)
        InstanceCreated::class => fn (): AbstractOgnivoEvent => new InstanceCreated(
            instanceId: 1,
            name: 'vm-01',
            hostname: 'vm-01.example.com',
            cpu: 2,
            memoryMb: 2048,
            diskGb: 40,
            image: 'ubuntu/24.04',
            actor: $actor,
        ),
        InstanceStarted::class => fn (): AbstractOgnivoEvent => new InstanceStarted(
            instanceId: 1,
            name: 'vm-01',
            actor: $actor,
        ),
        InstanceStopped::class => fn (): AbstractOgnivoEvent => new InstanceStopped(
            instanceId: 1,
            name: 'vm-01',
            actor: $actor,
        ),
        InstanceRebooted::class => fn (): AbstractOgnivoEvent => new InstanceRebooted(
            instanceId: 1,
            name: 'vm-01',
            actor: $actor,
        ),
        InstanceTerminated::class => fn (): AbstractOgnivoEvent => new InstanceTerminated(
            instanceId: 1,
            name: 'vm-01',
            actor: $actor,
        ),
        InstanceResized::class => fn (): AbstractOgnivoEvent => new InstanceResized(
            instanceId: 1,
            oldSpecs: ['cpu' => 2, 'memory_mb' => 2048, 'disk_gb' => 40],
            newSpecs: ['cpu' => 4, 'memory_mb' => 4096, 'disk_gb' => 80],
            actor: $actor,
        ),

        // Host (4)
        HostRegistered::class => fn (): AbstractOgnivoEvent => new HostRegistered(
            hostId: 1,
            name: 'host-01',
            hostname: 'host-01.example.com',
            ipAddress: '192.0.2.10',
            actor: $actor,
        ),
        HostStatusUpdated::class => fn (): AbstractOgnivoEvent => new HostStatusUpdated(
            hostId: 1,
            oldStatus: 'online',
            newStatus: 'offline',
            actor: $actor,
        ),
        HostMaintenanceEntered::class => fn (): AbstractOgnivoEvent => new HostMaintenanceEntered(
            hostId: 1,
            reason: 'Scheduled maintenance',
            actor: $actor,
        ),
        HostMaintenanceExited::class => fn (): AbstractOgnivoEvent => new HostMaintenanceExited(
            hostId: 1,
            actor: $actor,
        ),

        // Storage (4)
        VolumeCreated::class => fn (): AbstractOgnivoEvent => new VolumeCreated(
            volumeId: 10,
            name: 'vol-01',
            sizeGb: 50,
            pool: 'default',
            actor: $actor,
        ),
        VolumeAttached::class => fn (): AbstractOgnivoEvent => new VolumeAttached(
            volumeId: 10,
            instanceId: 1,
            devicePath: '/dev/sdb',
            actor: $actor,
        ),
        VolumeDetached::class => fn (): AbstractOgnivoEvent => new VolumeDetached(
            volumeId: 10,
            instanceId: 1,
            actor: $actor,
        ),
        SnapshotCreated::class => fn (): AbstractOgnivoEvent => new SnapshotCreated(
            snapshotId: 20,
            instanceId: 1,
            name: 'snap-01',
            actor: $actor,
        ),

        // Access / System (3)
        UserRoleAssigned::class => fn (): AbstractOgnivoEvent => new UserRoleAssigned(
            userId: 42,
            role: 'admin',
            actor: $actor,
        ),
        ServiceTokenCreated::class => fn (): AbstractOgnivoEvent => new ServiceTokenCreated(
            tokenId: 7,
            name: 'ci-token',
            abilities: ['instances:read'],
            actor: $actor,
        ),
        MaintenanceWindowScheduled::class => fn (): AbstractOgnivoEvent => new MaintenanceWindowScheduled(
            title: 'Hypervisor upgrade',
            startsAt: '2026-12-01T02:00:00Z',
            endsAt: '2026-12-01T04:00:00Z',
            actor: $actor,
        ),
    ];
}

dataset('domainEvents', function (): array {
    $rows = [];

    foreach (domainEventContractFactories() as $eventClass => $factory) {
        $rows[$eventClass] = [$eventClass, $factory];
    }

    return $rows;
});

it('registers all 17 expected domain events with unique dot-notation event names', function (): void {
    $factories = domainEventContractFactories();
    $names = array_map(
        static fn (callable $factory): string => $factory()->getEventName(),
        $factories,
    );

    expect($factories)->toHaveCount(17)
        ->and(array_unique($names))->toHaveCount(17);
});

it('adheres to the Ognivo domain event contract', function (string $eventClass, callable $factory): void {
    expect(class_exists($eventClass))->toBeTrue()
        ->and(is_subclass_of($eventClass, AbstractOgnivoEvent::class))->toBeTrue()
        ->and(is_subclass_of($eventClass, OgnivoEventInterface::class))->toBeTrue()
        ->and(is_subclass_of($eventClass, ShouldDispatchAfterCommit::class))->toBeTrue();

    /** @var AbstractOgnivoEvent $event */
    $event = $factory();

    expect($event)
        ->toBeInstanceOf($eventClass)
        ->toBeInstanceOf(AbstractOgnivoEvent::class)
        ->toBeInstanceOf(OgnivoEventInterface::class)
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class)
        ->and($event->getEventName())
        ->toBeString()
        ->not->toBeEmpty()
        ->toMatch('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/')
        ->and($event->getPayload())
        ->toBeArray()
        ->not->toBeEmpty();
})->with('domainEvents');
