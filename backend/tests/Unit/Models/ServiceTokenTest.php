<?php

declare(strict_types=1);

use App\Models\ServiceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates a service token with a sha256 hash and srv_ prefixed plain text token', function (): void {
    $creator = User::factory()->create();
    $expiresAt = Carbon::now()->addDays(30)->startOfSecond();

    $result = ServiceToken::generate(
        name: 'backup-daemon',
        abilities: ['vms:read', 'snapshots:create'],
        expiresAt: $expiresAt,
        creator: $creator,
    );

    $token = $result['token'];
    $plainTextToken = $result['plain_text_token'];

    expect($token)->toBeInstanceOf(ServiceToken::class)
        ->and($token->exists)->toBeTrue()
        ->and($token->name)->toBe('backup-daemon')
        ->and($token->abilities)->toBe(['vms:read', 'snapshots:create'])
        ->and($token->created_by_user_id)->toBe($creator->id)
        ->and($token->createdBy)->not->toBeNull()
        ->and($token->createdBy?->is($creator))->toBeTrue()
        ->and($token->expires_at?->equalTo($expiresAt))->toBeTrue()
        ->and($plainTextToken)->toStartWith('srv_')
        ->and(strlen($plainTextToken))->toBe(44);

    $secret = substr($plainTextToken, 4);
    expect($secret)->toMatch('/^[0-9a-f]{40}$/')
        ->and($token->token_hash)->toBe(hash('sha256', $secret))
        ->and($token->toArray())->not->toHaveKey('token_hash');
});

it('generates a service token with nullable expires_at and creator', function (): void {
    $result = ServiceToken::generate(
        name: 'monitoring-agent',
        abilities: ['metrics:read'],
    );

    $token = $result['token'];

    expect($token->expires_at)->toBeNull()
        ->and($token->created_by_user_id)->toBeNull()
        ->and($token->createdBy)->toBeNull()
        ->and($token->last_used_at)->toBeNull();
});

it('checks abilities including wildcard support', function (): void {
    $scopedToken = ServiceToken::factory()
        ->withAbilities(['vms:read', 'hosts:read'])
        ->create();

    expect($scopedToken->hasAbility('vms:read'))->toBeTrue()
        ->and($scopedToken->hasAbility('hosts:read'))->toBeTrue()
        ->and($scopedToken->hasAbility('vms:delete'))->toBeFalse()
        ->and($scopedToken->hasAbility('*'))->toBeFalse();

    $wildcardToken = ServiceToken::factory()
        ->withAbilities(['*'])
        ->create();

    expect($wildcardToken->hasAbility('vms:read'))->toBeTrue()
        ->and($wildcardToken->hasAbility('vms:delete'))->toBeTrue()
        ->and($wildcardToken->hasAbility('anything:else'))->toBeTrue();

    $emptyToken = ServiceToken::factory()
        ->withAbilities([])
        ->create();

    expect($emptyToken->hasAbility('vms:read'))->toBeFalse();
});

it('checks expiration date via isValid', function (): void {
    $neverExpires = ServiceToken::factory()->create([
        'expires_at' => null,
    ]);

    $futureToken = ServiceToken::factory()->create([
        'expires_at' => Carbon::now()->addHour(),
    ]);

    $expiredToken = ServiceToken::factory()->expired()->create();

    expect($neverExpires->isValid())->toBeTrue()
        ->and($futureToken->isValid())->toBeTrue()
        ->and($expiredToken->isValid())->toBeFalse();
});

it('nulls created_by_user_id when creator user is deleted', function (): void {
    $creator = User::factory()->create();

    $result = ServiceToken::generate(
        name: 'ephemeral-creator-token',
        abilities: ['vms:read'],
        creator: $creator,
    );

    $token = $result['token'];
    $creator->delete();
    $token->refresh();

    expect($token->created_by_user_id)->toBeNull()
        ->and($token->createdBy)->toBeNull();
});
