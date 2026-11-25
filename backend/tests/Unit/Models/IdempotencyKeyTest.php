<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('creates an idempotency key record with expected attributes and casts', function (): void {
    $lockedAt = Carbon::now()->startOfSecond();
    $expiresAt = Carbon::now()->addHours(24)->startOfSecond();
    $requestHash = hash('sha256', 'POST|/api/v1/vms|{"name":"web-1"}');

    $record = IdempotencyKey::query()->create([
        'key' => 'req-123e4567-e89b-12d3-a456-426614174000',
        'request_hash' => $requestHash,
        'response_status' => 201,
        'response_headers' => ['Content-Type' => ['application/json']],
        'response_body' => '{"id":1}',
        'locked_at' => $lockedAt,
        'expires_at' => $expiresAt,
    ]);

    $fresh = $record->fresh();
    expect($fresh)->not->toBeNull();
    assert($fresh instanceof IdempotencyKey);

    expect($fresh->key)->toBe('req-123e4567-e89b-12d3-a456-426614174000')
        ->and($fresh->request_hash)->toBe($requestHash)
        ->and($fresh->response_status)->toBe(201)
        ->and($fresh->response_headers)->toBe(['Content-Type' => ['application/json']])
        ->and($fresh->response_body)->toBe('{"id":1}')
        ->and($fresh->locked_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->locked_at?->equalTo($lockedAt))->toBeTrue()
        ->and($fresh->expires_at)->toBeInstanceOf(Carbon::class)
        ->and($fresh->expires_at->equalTo($expiresAt))->toBeTrue();
});

it('checks active lock status via isLocked', function (): void {
    $activeLock = IdempotencyKey::query()->create([
        'key' => 'locked-recent',
        'request_hash' => hash('sha256', 'a'),
        'response_status' => null,
        'locked_at' => Carbon::now()->subSeconds(10),
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $staleLock = IdempotencyKey::query()->create([
        'key' => 'locked-stale',
        'request_hash' => hash('sha256', 'b'),
        'response_status' => null,
        'locked_at' => Carbon::now()->subSeconds(31),
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $unlocked = IdempotencyKey::query()->create([
        'key' => 'unlocked',
        'request_hash' => hash('sha256', 'c'),
        'response_status' => null,
        'locked_at' => null,
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $completedWithRecentLock = IdempotencyKey::query()->create([
        'key' => 'completed-recent',
        'request_hash' => hash('sha256', 'd'),
        'response_status' => 200,
        'locked_at' => Carbon::now()->subSeconds(5),
        'expires_at' => Carbon::now()->addHour(),
    ]);

    expect($activeLock->isLocked())->toBeTrue()
        ->and($staleLock->isLocked())->toBeFalse()
        ->and($unlocked->isLocked())->toBeFalse()
        ->and($completedWithRecentLock->isLocked())->toBeFalse();
});

it('checks completion status via isCompleted', function (): void {
    $pending = IdempotencyKey::query()->create([
        'key' => 'pending-key',
        'request_hash' => hash('sha256', 'pending'),
        'response_status' => null,
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $completed = IdempotencyKey::query()->create([
        'key' => 'completed-key',
        'request_hash' => hash('sha256', 'completed'),
        'response_status' => 200,
        'expires_at' => Carbon::now()->addHour(),
    ]);

    expect($pending->isCompleted())->toBeFalse()
        ->and($completed->isCompleted())->toBeTrue();
});

it('checks expiration via isExpired', function (): void {
    $active = IdempotencyKey::query()->create([
        'key' => 'active-key',
        'request_hash' => hash('sha256', 'active'),
        'expires_at' => Carbon::now()->addMinute(),
    ]);

    $expired = IdempotencyKey::query()->create([
        'key' => 'expired-key',
        'request_hash' => hash('sha256', 'expired'),
        'expires_at' => Carbon::now()->subMinute(),
    ]);

    expect($active->isExpired())->toBeFalse()
        ->and($expired->isExpired())->toBeTrue();
});

it('compares request checksums safely via matchesChecksum', function (): void {
    $expectedHash = hash('sha256', 'POST|/api/v1/vms|{}');

    $record = IdempotencyKey::query()->create([
        'key' => 'checksum-key',
        'request_hash' => $expectedHash,
        'expires_at' => Carbon::now()->addHour(),
    ]);

    expect($record->matchesChecksum($expectedHash))->toBeTrue()
        ->and($record->matchesChecksum(hash('sha256', 'POST|/api/v1/vms|{"other":1}')))->toBeFalse()
        ->and($record->matchesChecksum('short'))->toBeFalse();
});
