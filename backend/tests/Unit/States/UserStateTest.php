<?php

declare(strict_types=1);

use App\Models\User;
use App\States\User\Active;
use App\States\User\Deleting;
use App\States\User\DeletionFailed;
use App\States\User\Inactive;
use App\States\User\Pending;
use App\States\User\Suspended;
use App\States\User\UserState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults User status to Active', function (): void {
    $user = new User;

    expect($user->status)
        ->toBeInstanceOf(UserState::class)
        ->toBeInstanceOf(Active::class)
        ->and($user->status->getValue())->toBe('active');
});

it('exposes all state values and assignable values', function (): void {
    expect(UserState::values())->toBe([
        'pending',
        'active',
        'suspended',
        'inactive',
        'deleting',
        'deletion_failed',
    ])->and(UserState::assignableValues())->toBe([
        'active',
        'suspended',
        'inactive',
        'pending',
    ]);
});

it('resolves inactive message keys for non-active states', function (string $stateClass, string $expectedKey): void {
    $user = User::factory()->make(['status' => $stateClass]);

    expect($user->status->inactiveMessageKey())->toBe($expectedKey);
})->with([
    'Pending' => [Pending::class, 'messages.auth.account_pending_verification'],
    'Suspended' => [Suspended::class, 'messages.auth.account_suspended'],
    'Inactive' => [Inactive::class, 'messages.auth.account_inactive'],
    'Deleting' => [Deleting::class, 'messages.auth.account_inactive'],
    'DeletionFailed' => [DeletionFailed::class, 'messages.auth.account_inactive'],
]);

it('allows valid UserState transitions', function (string $from, string $to): void {
    $user = User::factory()->create(['status' => $from]);

    $user->status->transitionTo($to);

    $fresh = $user->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->status)->toBeInstanceOf($to);
})->with([
    'Pending -> Active' => [Pending::class, Active::class],
    'Pending -> Suspended' => [Pending::class, Suspended::class],
    'Pending -> Inactive' => [Pending::class, Inactive::class],
    'Pending -> Deleting' => [Pending::class, Deleting::class],
    'Active -> Pending' => [Active::class, Pending::class],
    'Active -> Suspended' => [Active::class, Suspended::class],
    'Active -> Inactive' => [Active::class, Inactive::class],
    'Active -> Deleting' => [Active::class, Deleting::class],
    'Suspended -> Active' => [Suspended::class, Active::class],
    'Suspended -> Inactive' => [Suspended::class, Inactive::class],
    'Suspended -> Deleting' => [Suspended::class, Deleting::class],
    'Inactive -> Active' => [Inactive::class, Active::class],
    'Inactive -> Suspended' => [Inactive::class, Suspended::class],
    'Inactive -> Deleting' => [Inactive::class, Deleting::class],
    'Deleting -> DeletionFailed' => [Deleting::class, DeletionFailed::class],
    'DeletionFailed -> Deleting' => [DeletionFailed::class, Deleting::class],
    'DeletionFailed -> Inactive' => [DeletionFailed::class, Inactive::class],
    'DeletionFailed -> Suspended' => [DeletionFailed::class, Suspended::class],
]);

it('rejects disallowed UserState transitions', function (string $from, string $to): void {
    $user = User::factory()->create(['status' => $from]);

    expect(fn () => $user->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);
})->with([
    'Pending -> Pending' => [Pending::class, Pending::class],
    'Pending -> DeletionFailed' => [Pending::class, DeletionFailed::class],
    'Active -> Active' => [Active::class, Active::class],
    'Active -> DeletionFailed' => [Active::class, DeletionFailed::class],
    'Suspended -> Pending' => [Suspended::class, Pending::class],
    'Suspended -> Suspended' => [Suspended::class, Suspended::class],
    'Suspended -> DeletionFailed' => [Suspended::class, DeletionFailed::class],
    'Inactive -> Pending' => [Inactive::class, Pending::class],
    'Inactive -> Inactive' => [Inactive::class, Inactive::class],
    'Inactive -> DeletionFailed' => [Inactive::class, DeletionFailed::class],
    'Deleting -> Pending' => [Deleting::class, Pending::class],
    'Deleting -> Active' => [Deleting::class, Active::class],
    'Deleting -> Suspended' => [Deleting::class, Suspended::class],
    'Deleting -> Inactive' => [Deleting::class, Inactive::class],
    'Deleting -> Deleting' => [Deleting::class, Deleting::class],
    'DeletionFailed -> Pending' => [DeletionFailed::class, Pending::class],
    'DeletionFailed -> Active' => [DeletionFailed::class, Active::class],
    'DeletionFailed -> DeletionFailed' => [DeletionFailed::class, DeletionFailed::class],
]);
