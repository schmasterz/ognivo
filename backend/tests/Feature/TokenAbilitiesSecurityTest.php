<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class TokenAbilitiesSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->user = User::factory()->create([
            'name' => 'Test Tenant',
            'email' => 'tenant@example.test',
            'status' => 'active',
        ]);
        $this->user->assignRole('customer');
    }

    public function test_tokens_store_rejects_impersonated_ability(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'exploit-token',
            'abilities' => ['impersonated'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }

    public function test_tokens_store_rejects_impersonator_ability(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'exploit-token',
            'abilities' => ['impersonator:1'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['abilities.0']);
    }

    public function test_tokens_store_rejects_case_and_whitespace_variants_of_reserved_abilities(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/tokens', [
            'name' => 'variant-1',
            'abilities' => ['  IMPERSONATED  '],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);

        $this->postJson('/api/v1/tokens', [
            'name' => 'variant-2',
            'abilities' => ['impersonator:999'],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);

        $this->postJson('/api/v1/tokens', [
            'name' => 'variant-3',
            'abilities' => [''],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);

        $this->postJson('/api/v1/tokens', [
            'name' => 'variant-4',
            'abilities' => [123],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);
    }

    public function test_tokens_store_creates_token_with_valid_abilities_and_default_expiration(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/tokens', [
            'name' => 'normal-token',
            'abilities' => ['vms:read', 'snapshots:read'],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'message']);

        $token = $this->user->tokens()->where('name', 'normal-token')->first();
        $this->assertNotNull($token);
        $this->assertSame(['vms:read', 'snapshots:read'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertSame('2026-11-15 12:00:00', $token->expires_at->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_tokens_store_caps_custom_expiration_to_configured_maximum(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');

        Sanctum::actingAs($this->user);

        // Requested expiration within max (2 hours)
        $this->postJson('/api/v1/tokens', [
            'name' => 'short-lived-token',
            'expires_at' => Carbon::now()->addHours(2)->toIso8601String(),
        ])->assertStatus(201);

        $shortToken = $this->user->tokens()->where('name', 'short-lived-token')->first();
        $this->assertNotNull($shortToken);
        $this->assertSame('2026-11-14 14:00:00', $shortToken->expires_at?->toDateTimeString());

        // Requested expiration exceeding max (30 days) should be capped at 24 hours (1440 min)
        $this->postJson('/api/v1/tokens', [
            'name' => 'excessive-token',
            'expires_at' => Carbon::now()->addDays(30)->toIso8601String(),
        ])->assertStatus(201);

        $excessiveToken = $this->user->tokens()->where('name', 'excessive-token')->first();
        $this->assertNotNull($excessiveToken);
        $this->assertSame('2026-11-15 12:00:00', $excessiveToken->expires_at?->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_api_credentials_store_rejects_reserved_abilities(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/api-credentials', [
            'name' => 'exploit-cred-1',
            'abilities' => ['impersonated'],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);

        $this->postJson('/api/v1/api-credentials', [
            'name' => 'exploit-cred-2',
            'abilities' => ['impersonator:1'],
        ])->assertStatus(422)->assertJsonValidationErrors(['abilities.0']);
    }

    public function test_api_credentials_store_creates_credential_with_expiration(): void
    {
        Carbon::setTestNow('2026-11-14 12:00:00');

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/api-credentials', [
            'name' => 'normal-cred',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['token', 'message']);

        $token = $this->user->tokens()->where('name', 'normal-cred')->first();
        $this->assertNotNull($token);
        $this->assertSame(['*'], $token->abilities);
        $this->assertNotNull($token->expires_at);
        $this->assertSame('2026-11-15 12:00:00', $token->expires_at->toDateTimeString());

        Carbon::setTestNow();
    }
}
