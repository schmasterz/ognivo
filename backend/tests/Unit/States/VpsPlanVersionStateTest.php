<?php

declare(strict_types=1);

use App\Models\VpsPlanVersion;
use App\States\VpsPlanVersion\Draft;
use App\States\VpsPlanVersion\Published;
use App\States\VpsPlanVersion\Superseded;
use App\States\VpsPlanVersion\VpsPlanVersionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\TransitionNotFound;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults VpsPlanVersion status to Draft', function (): void {
    $version = new VpsPlanVersion;

    expect($version->status)
        ->toBeInstanceOf(VpsPlanVersionState::class)
        ->toBeInstanceOf(Draft::class)
        ->and($version->status->getValue())->toBe('draft');
});

it('exposes all valid VpsPlanVersionState morph values', function (): void {
    expect(VpsPlanVersionState::values())->toBe([
        'draft',
        'published',
        'superseded',
    ]);
});

it('allows valid VpsPlanVersionState transitions', function (string $from, string $to): void {
    $version = VpsPlanVersion::factory()->create([
        'status' => $from,
    ]);

    expect($version->status->canTransitionTo($to))->toBeTrue();

    $version->status->transitionTo($to);

    $fresh = $version->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBeInstanceOf($to);
})->with([
    'Draft -> Published' => [Draft::class, Published::class],
    'Published -> Superseded' => [Published::class, Superseded::class],
]);

it('rejects disallowed VpsPlanVersionState transitions', function (string $from, string $to): void {
    $version = VpsPlanVersion::factory()->create([
        'status' => $from,
    ]);

    expect($version->status->canTransitionTo($to))->toBeFalse();

    expect(fn () => $version->status->transitionTo($to))
        ->toThrow(TransitionNotFound::class);

    expect($version->fresh()?->status)->toBeInstanceOf($from);
})->with([
    'Draft -> Draft' => [Draft::class, Draft::class],
    'Draft -> Superseded' => [Draft::class, Superseded::class],
    'Published -> Draft' => [Published::class, Draft::class],
    'Published -> Published' => [Published::class, Published::class],
    'Superseded -> Draft' => [Superseded::class, Draft::class],
    'Superseded -> Published' => [Superseded::class, Published::class],
    'Superseded -> Superseded' => [Superseded::class, Superseded::class],
]);

it('treats Superseded as a terminal state', function (): void {
    $version = VpsPlanVersion::factory()->superseded()->create();

    expect($version->status->transitionableStates())->toBeEmpty();
});

it('allows updating and deleting a draft VpsPlanVersion', function (): void {
    $version = VpsPlanVersion::factory()->create([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    $version->update([
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
    ]);

    expect($version->fresh())
        ->cpu_cores->toBe(4)
        ->ram_mb->toBe(4096)
        ->disk_gb->toBe(40);

    expect($version->delete())->toBeTrue();
    expect(VpsPlanVersion::query()->find($version->id))->toBeNull();
});

it('forbids updating non-status fields on a published VpsPlanVersion', function (): void {
    $version = VpsPlanVersion::factory()->published()->create([
        'cpu_cores' => 2,
    ]);

    expect(fn () => $version->update(['cpu_cores' => 8]))
        ->toThrow(LogicException::class);

    expect($version->fresh()?->cpu_cores)->toBe(2);
});

it('forbids updating other fields alongside status transition on a published VpsPlanVersion', function (): void {
    $version = VpsPlanVersion::factory()->published()->create([
        'cpu_cores' => 2,
    ]);

    expect(function () use ($version): void {
        $version->cpu_cores = 8;
        $version->status->transitionTo(Superseded::class);
    })->toThrow(LogicException::class);

    $fresh = $version->fresh();
    expect($fresh)
        ->cpu_cores->toBe(2)
        ->and($fresh?->status)->toBeInstanceOf(Published::class);
});

it('forbids deleting a published VpsPlanVersion', function (): void {
    $version = VpsPlanVersion::factory()->published()->create();

    expect(fn () => $version->delete())
        ->toThrow(LogicException::class);

    expect(VpsPlanVersion::query()->find($version->id))->not->toBeNull();
});

it('forbids updating or deleting a superseded VpsPlanVersion', function (): void {
    $version = VpsPlanVersion::factory()->superseded()->create([
        'cpu_cores' => 2,
    ]);

    expect(fn () => $version->update(['cpu_cores' => 4]))
        ->toThrow(LogicException::class);

    expect(fn () => $version->delete())
        ->toThrow(LogicException::class);

    expect($version->fresh()?->cpu_cores)->toBe(2);
});
