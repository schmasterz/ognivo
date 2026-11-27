<?php

declare(strict_types=1);

use App\Events\SnapshotStatusUpdated;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\States\Snapshot\Creating;
use App\States\Snapshot\Deleting;
use App\States\Snapshot\Failed;
use App\States\Snapshot\Ready;
use App\States\Snapshot\Restoring;
use App\States\Snapshot\SnapshotState;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults VmSnapshot status to Creating', function (): void {
    $snapshot = new VmSnapshot;

    expect($snapshot->status)
        ->toBeInstanceOf(SnapshotState::class)
        ->toBeInstanceOf(Creating::class)
        ->and($snapshot->status->getValue())->toBe('creating');
});

it('allows valid SnapshotState transitions', function (string $from, string $to): void {
    Event::fake([SnapshotStatusUpdated::class]);

    $snapshot = VmSnapshot::factory()->create(['status' => $from]);

    $snapshot->status->transitionTo($to);

    $fresh = $snapshot->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBeInstanceOf($to);

    Event::assertDispatched(
        SnapshotStatusUpdated::class,
        fn (SnapshotStatusUpdated $event): bool => $event->snapshot->is($snapshot)
            && $event->status === $to::getMorphClass()
    );
})->with([
    'Creating -> Ready' => [Creating::class, Ready::class],
    'Creating -> Failed' => [Creating::class, Failed::class],
    'Ready -> Restoring' => [Ready::class, Restoring::class],
    'Ready -> Deleting' => [Ready::class, Deleting::class],
    'Ready -> Failed' => [Ready::class, Failed::class],
    'Restoring -> Ready' => [Restoring::class, Ready::class],
    'Restoring -> Failed' => [Restoring::class, Failed::class],
    'Deleting -> Failed' => [Deleting::class, Failed::class],
    'Failed -> Deleting' => [Failed::class, Deleting::class],
    'Failed -> Failed' => [Failed::class, Failed::class],
]);

it('rejects disallowed SnapshotState transitions', function (string $from, string $to): void {
    $snapshot = VmSnapshot::factory()->create(['status' => $from]);

    expect(fn () => $snapshot->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);
})->with([
    'Creating -> Creating' => [Creating::class, Creating::class],
    'Creating -> Restoring' => [Creating::class, Restoring::class],
    'Creating -> Deleting' => [Creating::class, Deleting::class],
    'Ready -> Creating' => [Ready::class, Creating::class],
    'Ready -> Ready' => [Ready::class, Ready::class],
    'Restoring -> Creating' => [Restoring::class, Creating::class],
    'Restoring -> Restoring' => [Restoring::class, Restoring::class],
    'Restoring -> Deleting' => [Restoring::class, Deleting::class],
    'Deleting -> Creating' => [Deleting::class, Creating::class],
    'Deleting -> Ready' => [Deleting::class, Ready::class],
    'Deleting -> Restoring' => [Deleting::class, Restoring::class],
    'Deleting -> Deleting' => [Deleting::class, Deleting::class],
    'Failed -> Creating' => [Failed::class, Creating::class],
    'Failed -> Ready' => [Failed::class, Ready::class],
    'Failed -> Restoring' => [Failed::class, Restoring::class],
]);

it('ignores non-VmSnapshot StateChanged events in SnapshotStateChangedListener', function (): void {
    Event::fake([SnapshotStatusUpdated::class]);

    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'status' => Stopped::class,
    ]);

    $vm->status->transitionTo(Running::class);

    Event::assertNotDispatched(SnapshotStatusUpdated::class);
});
