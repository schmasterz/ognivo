<?php

declare(strict_types=1);

use App\Events\Contracts\OgnivoEventInterface;
use App\Events\Instance\InstanceCreated;
use App\Events\Instance\InstanceRebooted;
use App\Events\Instance\InstanceResized;
use App\Events\Instance\InstanceStarted;
use App\Events\Instance\InstanceStopped;
use App\Events\Instance\InstanceTerminated;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * @return array<string, array{
 *     factory: callable(array{type: string, id: string|null, label: string|null}|null): OgnivoEventInterface,
 *     expectedName: string,
 *     expectedPayload: array<string, mixed>,
 * }>
 */
dataset('instanceLifecycleEvents', [
    'instance.created' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceCreated(
            instanceId: 101,
            name: 'web-01',
            hostname: 'web-01.example.com',
            cpu: 4,
            memoryMb: 4096,
            diskGb: 80,
            image: 'ubuntu/24.04',
            actor: $actor,
        ),
        'expectedName' => 'instance.created',
        'expectedPayload' => [
            'instance_id' => 101,
            'name' => 'web-01',
            'hostname' => 'web-01.example.com',
            'cpu' => 4,
            'memory_mb' => 4096,
            'disk_gb' => 80,
            'image' => 'ubuntu/24.04',
        ],
    ],
    'instance.started' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceStarted(
            instanceId: 102,
            name: 'web-02',
            actor: $actor,
        ),
        'expectedName' => 'instance.started',
        'expectedPayload' => [
            'instance_id' => 102,
            'name' => 'web-02',
            'status' => 'running',
        ],
    ],
    'instance.stopped' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceStopped(
            instanceId: 103,
            name: 'web-03',
            actor: $actor,
        ),
        'expectedName' => 'instance.stopped',
        'expectedPayload' => [
            'instance_id' => 103,
            'name' => 'web-03',
            'status' => 'stopped',
        ],
    ],
    'instance.rebooted' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceRebooted(
            instanceId: 104,
            name: 'web-04',
            actor: $actor,
        ),
        'expectedName' => 'instance.rebooted',
        'expectedPayload' => [
            'instance_id' => 104,
            'name' => 'web-04',
            'status' => 'running',
        ],
    ],
    'instance.terminated' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceTerminated(
            instanceId: 105,
            name: 'web-05',
            actor: $actor,
        ),
        'expectedName' => 'instance.terminated',
        'expectedPayload' => [
            'instance_id' => 105,
            'name' => 'web-05',
        ],
    ],
    'instance.resized' => [
        'factory' => fn (?array $actor): OgnivoEventInterface => new InstanceResized(
            instanceId: 106,
            oldSpecs: ['cpu' => 2, 'memory_mb' => 2048, 'disk_gb' => 40],
            newSpecs: ['cpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
            actor: $actor,
        ),
        'expectedName' => 'instance.resized',
        'expectedPayload' => [
            'instance_id' => 106,
            'old_specs' => ['cpu' => 2, 'memory_mb' => 2048, 'disk_gb' => 40],
            'new_specs' => ['cpu' => 4, 'memory_mb' => 8192, 'disk_gb' => 80],
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
})->with('instanceLifecycleEvents');

it('defaults actor to null without application context', function (
    callable $factory,
    string $expectedName,
    array $expectedPayload,
): void {
    /** @var OgnivoEventInterface $event */
    $event = $factory(null);

    expect($event->getActor())->toBeNull()
        ->and($event->getEventName())->toBe($expectedName)
        ->and($event->getPayload())->toBe($expectedPayload)
        ->and($event->getOccurredAt()->getTimezone()->getName())->toBe('UTC');
})->with('instanceLifecycleEvents');

it('supports custom statuses on transition events and partial specs on resize', function (): void {
    $started = new InstanceStarted(10, 'app-01', 'starting');
    $stopped = new InstanceStopped(11, 'app-02', 'stopping');
    $rebooted = new InstanceRebooted(12, 'app-03', 'rebooting');
    $resized = new InstanceResized(13, ['memory_mb' => 1024], ['memory_mb' => 2048]);

    expect($started->getPayload())->toBe(['instance_id' => 10, 'name' => 'app-01', 'status' => 'starting'])
        ->and($stopped->getPayload())->toBe(['instance_id' => 11, 'name' => 'app-02', 'status' => 'stopping'])
        ->and($rebooted->getPayload())->toBe(['instance_id' => 12, 'name' => 'app-03', 'status' => 'rebooting'])
        ->and($resized->getPayload())->toBe([
            'instance_id' => 13,
            'old_specs' => ['memory_mb' => 1024],
            'new_specs' => ['memory_mb' => 2048],
        ]);
});
