<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use App\States\Vm\VmState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults VirtualMachine status to Provisioning', function (): void {
    $vm = new VirtualMachine;

    expect($vm->status)
        ->toBeInstanceOf(VmState::class)
        ->toBeInstanceOf(Provisioning::class)
        ->and($vm->status->getValue())->toBe('provisioning');
});

it('exposes all valid VmState morph values', function (): void {
    expect(VmState::values())->toBe([
        'provisioning',
        'running',
        'stopped',
        'suspended',
        'deleting',
        'deleted',
        'failed',
    ]);
});

it('allows valid VmState transitions', function (string $from, string $to): void {
    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'status' => $from,
    ]);

    expect($vm->status->canTransitionTo($to))->toBeTrue();

    $vm->status->transitionTo($to);

    $fresh = $vm->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh->status)->toBeInstanceOf($to);
})->with([
    'Provisioning -> Running' => [Provisioning::class, Running::class],
    'Provisioning -> Deleting' => [Provisioning::class, Deleting::class],
    'Provisioning -> Failed' => [Provisioning::class, Failed::class],
    'Running -> Provisioning' => [Running::class, Provisioning::class],
    'Running -> Stopped' => [Running::class, Stopped::class],
    'Running -> Suspended' => [Running::class, Suspended::class],
    'Running -> Deleting' => [Running::class, Deleting::class],
    'Running -> Deleted' => [Running::class, Deleted::class],
    'Running -> Failed' => [Running::class, Failed::class],
    'Stopped -> Provisioning' => [Stopped::class, Provisioning::class],
    'Stopped -> Running' => [Stopped::class, Running::class],
    'Stopped -> Suspended' => [Stopped::class, Suspended::class],
    'Stopped -> Deleting' => [Stopped::class, Deleting::class],
    'Stopped -> Deleted' => [Stopped::class, Deleted::class],
    'Stopped -> Failed' => [Stopped::class, Failed::class],
    'Suspended -> Provisioning' => [Suspended::class, Provisioning::class],
    'Suspended -> Running' => [Suspended::class, Running::class],
    'Suspended -> Stopped' => [Suspended::class, Stopped::class],
    'Suspended -> Deleting' => [Suspended::class, Deleting::class],
    'Suspended -> Deleted' => [Suspended::class, Deleted::class],
    'Suspended -> Failed' => [Suspended::class, Failed::class],
    'Deleting -> Deleted' => [Deleting::class, Deleted::class],
    'Deleting -> Failed' => [Deleting::class, Failed::class],
    'Failed -> Provisioning' => [Failed::class, Provisioning::class],
    'Failed -> Deleting' => [Failed::class, Deleting::class],
    'Failed -> Deleted' => [Failed::class, Deleted::class],
]);

it('rejects disallowed and redundant self VmState transitions', function (string $from, string $to): void {
    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'status' => $from,
    ]);

    expect($vm->status->canTransitionTo($to))->toBeFalse();

    expect(fn () => $vm->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);

    expect($vm->fresh()?->status)->toBeInstanceOf($from);
})->with([
    'Provisioning -> Provisioning' => [Provisioning::class, Provisioning::class],
    'Provisioning -> Stopped' => [Provisioning::class, Stopped::class],
    'Provisioning -> Suspended' => [Provisioning::class, Suspended::class],
    'Provisioning -> Deleted' => [Provisioning::class, Deleted::class],
    'Running -> Running' => [Running::class, Running::class],
    'Stopped -> Stopped' => [Stopped::class, Stopped::class],
    'Suspended -> Suspended' => [Suspended::class, Suspended::class],
    'Deleting -> Provisioning' => [Deleting::class, Provisioning::class],
    'Deleting -> Running' => [Deleting::class, Running::class],
    'Deleting -> Stopped' => [Deleting::class, Stopped::class],
    'Deleting -> Suspended' => [Deleting::class, Suspended::class],
    'Deleting -> Deleting' => [Deleting::class, Deleting::class],
    'Deleted -> Provisioning' => [Deleted::class, Provisioning::class],
    'Deleted -> Running' => [Deleted::class, Running::class],
    'Deleted -> Stopped' => [Deleted::class, Stopped::class],
    'Deleted -> Suspended' => [Deleted::class, Suspended::class],
    'Deleted -> Deleting' => [Deleted::class, Deleting::class],
    'Deleted -> Deleted' => [Deleted::class, Deleted::class],
    'Deleted -> Failed' => [Deleted::class, Failed::class],
    'Failed -> Running' => [Failed::class, Running::class],
    'Failed -> Stopped' => [Failed::class, Stopped::class],
    'Failed -> Suspended' => [Failed::class, Suspended::class],
    'Failed -> Failed' => [Failed::class, Failed::class],
]);
