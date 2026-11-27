<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use App\States\VpsPlanChange\VpsPlanChangeState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * @return list<class-string<VpsPlanChangeState>>
 */
function allPlanChangeStates(): array
{
    return [
        Pending::class,
        AwaitingAuthorization::class,
        Applying::class,
        Completed::class,
        Failed::class,
        Cancelled::class,
        ReconciliationRequired::class,
    ];
}

/**
 * @return array<class-string<VpsPlanChangeState>, list<class-string<VpsPlanChangeState>>>
 */
function allowedPlanChangeTransitions(): array
{
    return [
        AwaitingAuthorization::class => [Pending::class, Failed::class, Cancelled::class],
        Pending::class => [Applying::class, Cancelled::class, Failed::class],
        Applying::class => [Completed::class, Failed::class, ReconciliationRequired::class],
        ReconciliationRequired::class => [Completed::class, Failed::class, Applying::class],
        Completed::class => [],
        Failed::class => [],
        Cancelled::class => [],
    ];
}

it('defaults VpsPlanChange status to Pending', function (): void {
    $change = new VpsPlanChange;

    expect($change->status)
        ->toBeInstanceOf(VpsPlanChangeState::class)
        ->toBeInstanceOf(Pending::class)
        ->and($change->status->getValue())->toBe('pending');
});

it('exposes all valid and active VpsPlanChangeState morph values', function (): void {
    expect(VpsPlanChangeState::values())->toBe([
        'pending',
        'awaiting_authorization',
        'applying',
        'completed',
        'failed',
        'cancelled',
        'reconciliation_required',
    ]);

    expect(VpsPlanChangeState::activeValues())->toBe([
        'pending',
        'awaiting_authorization',
        'applying',
        'reconciliation_required',
    ]);
});

it('enforces the exhaustive 7x7 VpsPlanChangeState transition matrix', function (): void {
    $allStates = allPlanChangeStates();
    $allowedMap = allowedPlanChangeTransitions();

    $user = User::factory()->create();
    $vm = VirtualMachine::factory()->create(['user_id' => $user->id]);
    $toVersion = VpsPlanVersion::factory()->published()->create();

    $checkedPairs = 0;

    foreach ($allStates as $from) {
        foreach ($allStates as $to) {
            $checkedPairs++;
            $shouldAllow = in_array($to, $allowedMap[$from], true);

            $change = VpsPlanChange::factory()->create([
                'virtual_machine_id' => $vm->id,
                'user_id' => $user->id,
                'to_version_id' => $toVersion->id,
                'status' => $from,
            ]);

            expect($change->status->canTransitionTo($to))
                ->toBe($shouldAllow, "Expected {$from} -> {$to} canTransitionTo to be ".($shouldAllow ? 'true' : 'false'));

            if ($shouldAllow) {
                $change->status->transitionTo($to);

                $fresh = $change->fresh();
                expect($fresh)->not->toBeNull()
                    ->and($fresh?->status)->toBeInstanceOf($to);
            } else {
                expect(fn () => $change->status->transitionTo($to))
                    ->toThrow(TransitionNotFound::class);

                expect($change->fresh()?->status)->toBeInstanceOf($from);
            }
        }
    }

    expect($checkedPairs)->toBe(49);
});

it('exposes no transitionable states on terminal states', function (string $terminalState): void {
    $change = VpsPlanChange::factory()->create([
        'status' => $terminalState,
    ]);

    expect($change->status->transitionableStates())->toBeEmpty();
})->with([
    'Completed' => [Completed::class],
    'Failed' => [Failed::class],
    'Cancelled' => [Cancelled::class],
]);

it('computes isActive, holdsReservation, isTerminal, and canCancel correctly for every state', function (
    string $stateClass,
    bool $expectedActive,
    bool $expectedTerminal,
    bool $expectedCanCancel,
): void {
    $change = new VpsPlanChange(['status' => $stateClass]);

    expect($change->status->isActive())->toBe($expectedActive)
        ->and($change->status->holdsReservation())->toBe($expectedActive)
        ->and($change->status->isTerminal())->toBe($expectedTerminal)
        ->and($change->status->canCancel())->toBe($expectedCanCancel);
})->with([
    'Pending' => [Pending::class, true, false, true],
    'AwaitingAuthorization' => [AwaitingAuthorization::class, true, false, true],
    'Applying' => [Applying::class, true, false, false],
    'ReconciliationRequired' => [ReconciliationRequired::class, true, false, false],
    'Completed' => [Completed::class, false, true, false],
    'Failed' => [Failed::class, false, true, false],
    'Cancelled' => [Cancelled::class, false, true, false],
]);

it('auto-generates ULID public_id, casts fields, resolves relations, and scopes active plan changes', function (): void {
    $owner = User::factory()->create();
    $initiator = User::factory()->create();
    $vm = VirtualMachine::factory()->create(['user_id' => $owner->id]);
    $fromVersion = VpsPlanVersion::factory()->published()->create();
    $toVersion = VpsPlanVersion::factory()->published()->create();

    $pending = VpsPlanChange::query()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'initiated_by' => $initiator->id,
        'from_version_id' => $fromVersion->id,
        'to_version_id' => $toVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048],
        'new_resources' => ['cpu_cores' => 4, 'ram_mb' => 4096],
        'requested_at' => now(),
    ]);

    $awaiting = VpsPlanChange::factory()->awaitingAuthorization()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);
    $applying = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);
    $reconciling = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);
    $completed = VpsPlanChange::factory()->completed()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);
    $failed = VpsPlanChange::factory()->failed()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);
    $cancelled = VpsPlanChange::factory()->cancelled()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $owner->id,
        'to_version_id' => $toVersion->id,
    ]);

    expect(Str::isUlid($pending->public_id))->toBeTrue()
        ->and($pending->old_resources)->toBe(['cpu_cores' => 2, 'ram_mb' => 2048])
        ->and($pending->new_resources)->toBe(['cpu_cores' => 4, 'ram_mb' => 4096])
        ->and($pending->virtualMachine?->is($vm))->toBeTrue()
        ->and($pending->user?->is($owner))->toBeTrue()
        ->and($pending->initiator?->is($initiator))->toBeTrue()
        ->and($pending->fromVersion?->is($fromVersion))->toBeTrue()
        ->and($pending->toVersion?->is($toVersion))->toBeTrue();

    $activeIds = VpsPlanChange::query()->active()->pluck('id')->all();

    expect($activeIds)
        ->toHaveCount(4)
        ->toContain($pending->id, $awaiting->id, $applying->id, $reconciling->id)
        ->not->toContain($completed->id, $failed->id, $cancelled->id);
});
