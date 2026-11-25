<?php

declare(strict_types=1);

use App\Audit\AuditOutcome;
use App\Models\ServiceToken;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\AuditAssertionTrait;

uses(AuditAssertionTrait::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->admin = User::factory()->create([
        'name' => 'Root Admin',
        'email' => 'admin@ognivo.test',
        'status' => 'active',
    ]);
    $this->admin->assignRole('admin');

    $this->customer = User::factory()->create([
        'name' => 'Regular Customer',
        'email' => 'customer@ognivo.test',
        'status' => 'active',
    ]);
    $this->customer->assignRole('customer');
});

test('unauthenticated users receive 401 for service token endpoints', function (): void {
    $token = ServiceToken::factory()->create();

    $this->getJson('/api/v1/service-tokens')->assertUnauthorized();
    $this->postJson('/api/v1/service-tokens', [
        'name' => 'ci-runner',
        'abilities' => ['vms:read'],
    ])->assertUnauthorized();
    $this->deleteJson("/api/v1/service-tokens/{$token->id}")->assertUnauthorized();
});

test('non-admin users receive 403 for service token endpoints', function (): void {
    $token = ServiceToken::factory()->create();

    Sanctum::actingAs($this->customer);

    $this->getJson('/api/v1/service-tokens')->assertForbidden();
    $this->postJson('/api/v1/service-tokens', [
        'name' => 'ci-runner',
        'abilities' => ['vms:read'],
    ])->assertForbidden();
    $this->deleteJson("/api/v1/service-tokens/{$token->id}")->assertForbidden();
});

test('admin can list service tokens without exposing token_hash', function (): void {
    $first = ServiceToken::factory()->create([
        'name' => 'backup-daemon',
        'abilities' => ['vms:read', 'snapshots:create'],
        'created_by_user_id' => $this->admin->id,
    ]);
    $second = ServiceToken::factory()->create([
        'name' => 'metrics-scraper',
        'abilities' => ['metrics:read'],
        'expires_at' => Carbon::now()->addDays(7),
        'created_by_user_id' => $this->admin->id,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->getJson('/api/v1/service-tokens');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'abilities',
                    'last_used_at',
                    'expires_at',
                    'created_at',
                    'created_by_user_id',
                ],
            ],
        ]);

    /** @var array<int, array<string, mixed>> $items */
    $items = $response->json('data');
    expect($items[0])->not->toHaveKey('token_hash')
        ->and($items[1])->not->toHaveKey('token_hash')
        ->and(collect($items)->pluck('id')->all())->toBe([$second->id, $first->id]);
});

test('admin can create a service token and receives one-time srv_ plain text token and audit log', function (): void {
    $expiresAt = Carbon::now()->addDays(30)->startOfSecond();

    Sanctum::actingAs($this->admin);

    $response = $this->postJson('/api/v1/service-tokens', [
        'name' => 'automation-service',
        'abilities' => ['vms:read', 'hosts:read'],
        'expires_at' => $expiresAt->toIso8601String(),
    ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'token' => [
                'id',
                'name',
                'abilities',
                'expires_at',
                'created_at',
                'created_by_user_id',
            ],
            'plain_text_token',
        ]);

    /** @var string $plainTextToken */
    $plainTextToken = $response->json('plain_text_token');
    /** @var array<string, mixed> $tokenPayload */
    $tokenPayload = $response->json('token');

    expect($plainTextToken)->toStartWith('srv_')
        ->and($tokenPayload)->not->toHaveKey('token_hash')
        ->and($tokenPayload['name'])->toBe('automation-service')
        ->and($tokenPayload['abilities'])->toBe(['vms:read', 'hosts:read'])
        ->and($tokenPayload['created_by_user_id'])->toBe($this->admin->id);

    $createdToken = ServiceToken::query()->find($tokenPayload['id']);
    expect($createdToken)->not->toBeNull()
        ->and($createdToken?->token_hash)->toBe(hash('sha256', substr($plainTextToken, 4)));

    $auditRow = $this->assertAuditRow(
        action: 'service_token.created',
        outcome: AuditOutcome::Success->value,
        tenantUserId: null,
        visibility: 'admin',
    );

    expect($auditRow->subject_type)->toBe(ServiceToken::class)
        ->and($auditRow->subject_id)->toBe((string) $createdToken?->id)
        ->and($auditRow->subject_label)->toBe('automation-service')
        ->and($auditRow->actor_id)->toBe($this->admin->id);
});

test('service token creation validates required fields and constraints', function (): void {
    Sanctum::actingAs($this->admin);

    $this->postJson('/api/v1/service-tokens', [
        'name' => '',
        'abilities' => [],
        'expires_at' => Carbon::now()->subMinute()->toIso8601String(),
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'abilities', 'expires_at']);

    $this->postJson('/api/v1/service-tokens', [
        'name' => str_repeat('a', 101),
        'abilities' => [str_repeat('b', 51)],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'abilities.0']);
});

test('admin can revoke a service token and records audit log', function (): void {
    $token = ServiceToken::factory()->create([
        'name' => 'deprecated-runner',
        'created_by_user_id' => $this->admin->id,
    ]);

    Sanctum::actingAs($this->admin);

    $response = $this->deleteJson("/api/v1/service-tokens/{$token->id}");

    $response->assertOk();
    $this->assertDatabaseMissing('service_tokens', ['id' => $token->id]);

    $auditRow = $this->assertAuditRow(
        action: 'service_token.revoked',
        outcome: AuditOutcome::Success->value,
        tenantUserId: null,
        visibility: 'admin',
    );

    expect($auditRow->subject_type)->toBe(ServiceToken::class)
        ->and($auditRow->subject_id)->toBe((string) $token->id)
        ->and($auditRow->subject_label)->toBe('deprecated-runner')
        ->and($auditRow->actor_id)->toBe($this->admin->id);
});

test('revoking a non-existent service token returns 404', function (): void {
    Sanctum::actingAs($this->admin);

    $this->deleteJson('/api/v1/service-tokens/999999')->assertNotFound();
});
