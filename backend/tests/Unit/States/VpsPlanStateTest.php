<?php

declare(strict_types=1);

use App\Models\VpsPlan;
use App\States\VpsPlan\Archived;
use App\States\VpsPlan\Draft;
use App\States\VpsPlan\Published;
use App\States\VpsPlan\VpsPlanState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults VpsPlan status to Draft', function (): void {
    $plan = new VpsPlan;

    expect($plan->status)
        ->toBeInstanceOf(VpsPlanState::class)
        ->toBeInstanceOf(Draft::class)
        ->and($plan->status->getValue())->toBe('draft');
});

it('exposes all valid VpsPlanState morph values', function (): void {
    expect(VpsPlanState::values())->toBe([
        'draft',
        'published',
        'archived',
    ]);
});

it('allows valid VpsPlanState transitions', function (string $from, string $to): void {
    $plan = VpsPlan::factory()->create([
        'status' => $from,
    ]);

    expect($plan->status->canTransitionTo($to))->toBeTrue();

    $plan->status->transitionTo($to);

    $fresh = $plan->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBeInstanceOf($to);
})->with([
    'Draft -> Published' => [Draft::class, Published::class],
    'Published -> Draft' => [Published::class, Draft::class],
    'Published -> Archived' => [Published::class, Archived::class],
    'Draft -> Archived' => [Draft::class, Archived::class],
    'Archived -> Draft' => [Archived::class, Draft::class],
]);

it('rejects disallowed VpsPlanState transitions', function (string $from, string $to): void {
    $plan = VpsPlan::factory()->create([
        'status' => $from,
    ]);

    expect($plan->status->canTransitionTo($to))->toBeFalse();

    expect(fn () => $plan->status->transitionTo($to))
        ->toThrow(TransitionNotFound::class);

    expect($plan->fresh()?->status)->toBeInstanceOf($from);
})->with([
    'Draft -> Draft' => [Draft::class, Draft::class],
    'Published -> Published' => [Published::class, Published::class],
    'Archived -> Published' => [Archived::class, Published::class],
    'Archived -> Archived' => [Archived::class, Archived::class],
]);

it('has no terminal states in VpsPlanState', function (string $stateClass): void {
    $plan = VpsPlan::factory()->create([
        'status' => $stateClass,
    ]);

    expect($plan->status->transitionableStates())->not->toBeEmpty();
})->with([
    'Draft' => [Draft::class],
    'Published' => [Published::class],
    'Archived' => [Archived::class],
]);
