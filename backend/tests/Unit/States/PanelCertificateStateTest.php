<?php

declare(strict_types=1);

use App\Models\PanelCertificate;
use App\States\PanelCertificate\Active;
use App\States\PanelCertificate\PanelCertificateState;
use App\States\PanelCertificate\Retiring;
use App\States\PanelCertificate\Revoked;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\ModelStates\Exceptions\CouldNotPerformTransition;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('defaults PanelCertificate status to Active', function (): void {
    $cert = new PanelCertificate;

    expect($cert->status)
        ->toBeInstanceOf(PanelCertificateState::class)
        ->toBeInstanceOf(Active::class)
        ->and($cert->status->getValue())->toBe('active');
});

it('syncs active_marker via the saving hook on creation and state transitions', function (): void {
    $cert = PanelCertificate::create([
        'certificate' => 'dummy-cert',
        'private_key' => 'dummy-key',
        'fingerprint' => 'fp-sync-test',
        'not_before' => now(),
        'not_after' => now()->addYear(),
    ]);

    expect($cert->status)->toBeInstanceOf(Active::class)
        ->and($cert->active_marker)->toBe(1)
        ->and($cert->isUsable())->toBeTrue();

    $cert->status->transitionTo(Retiring::class);
    $cert->refresh();

    expect($cert->status)->toBeInstanceOf(Retiring::class)
        ->and($cert->active_marker)->toBeNull()
        ->and($cert->isUsable())->toBeTrue();

    $cert->status->transitionTo(Revoked::class);
    $cert->refresh();

    expect($cert->status)->toBeInstanceOf(Revoked::class)
        ->and($cert->active_marker)->toBeNull()
        ->and($cert->isUsable())->toBeFalse();
});

it('allows valid PanelCertificateState transitions', function (string $from, string $to): void {
    $cert = PanelCertificate::create([
        'certificate' => 'dummy-cert',
        'private_key' => 'dummy-key',
        'fingerprint' => 'fp-allowed-'.$from::getMorphClass().'-'.$to::getMorphClass(),
        'not_before' => now(),
        'not_after' => now()->addYear(),
        'status' => $from,
    ]);

    $cert->status->transitionTo($to);

    $fresh = $cert->fresh();
    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBeInstanceOf($to);
})->with([
    'Active -> Retiring' => [Active::class, Retiring::class],
    'Retiring -> Revoked' => [Retiring::class, Revoked::class],
]);

it('rejects disallowed PanelCertificateState transitions', function (string $from, string $to): void {
    $cert = PanelCertificate::create([
        'certificate' => 'dummy-cert',
        'private_key' => 'dummy-key',
        'fingerprint' => 'fp-disallowed-'.$from::getMorphClass().'-'.$to::getMorphClass(),
        'not_before' => now(),
        'not_after' => now()->addYear(),
        'status' => $from,
    ]);

    expect(fn () => $cert->status->transitionTo($to))
        ->toThrow(CouldNotPerformTransition::class);

    expect($cert->fresh()?->status)->toBeInstanceOf($from);
})->with([
    'Active -> Active' => [Active::class, Active::class],
    'Active -> Revoked' => [Active::class, Revoked::class],
    'Retiring -> Active' => [Retiring::class, Active::class],
    'Retiring -> Retiring' => [Retiring::class, Retiring::class],
    'Revoked -> Active' => [Revoked::class, Active::class],
    'Revoked -> Retiring' => [Revoked::class, Retiring::class],
    'Revoked -> Revoked' => [Revoked::class, Revoked::class],
]);
