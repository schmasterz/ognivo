<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class RouteThrottleAndChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->customer = User::factory()->create([
            'status' => 'active',
        ]);
        $this->customer->assignRole('customer');

        $this->admin = User::factory()->create([
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        RateLimiter::clear('vm-actions:user:'.$this->customer->id);
    }

    public function test_tokens_store_is_throttled_after_exceeding_limit(): void
    {
        Sanctum::actingAs($this->customer);

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/tokens', [
                'name' => "token-{$i}",
            ]);
            $response->assertStatus(201);
        }

        $throttledResponse = $this->postJson('/api/v1/tokens', [
            'name' => 'token-overflow',
        ]);

        $throttledResponse->assertStatus(429);
        $throttledResponse->assertHeader('Retry-After');
    }

    public function test_vm_store_is_throttled_for_non_admin_users(): void
    {
        Sanctum::actingAs($this->customer);

        // Even with unprocessable payload, throttle middleware runs and counts attempts
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/v1/vms', []);
            $response->assertStatus(422);
        }

        $throttledResponse = $this->postJson('/api/v1/vms', []);
        $throttledResponse->assertStatus(429);
        $throttledResponse->assertHeader('Retry-After');
        $throttledResponse->assertJsonStructure(['message', 'retry_after']);
    }

    public function test_vm_store_is_not_throttled_for_admin_users(): void
    {
        Sanctum::actingAs($this->admin);

        for ($i = 0; $i < 12; $i++) {
            $response = $this->postJson('/api/v1/vms', []);
            $response->assertStatus(422);
        }
    }

    public function test_broadcasting_auth_returns_403_for_suspended_or_inactive_user(): void
    {
        $suspendedUser = User::factory()->create([
            'status' => 'suspended',
        ]);
        $suspendedUser->assignRole('customer');

        Sanctum::actingAs($suspendedUser);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-users.'.$suspendedUser->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => __('messages.auth.account_suspended')]);

        $inactiveUser = User::factory()->create([
            'status' => 'inactive',
        ]);
        $inactiveUser->assignRole('customer');

        Sanctum::actingAs($inactiveUser);

        $inactiveResponse = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-users.'.$inactiveUser->id,
            'socket_id' => '1234.5678',
        ]);

        $inactiveResponse->assertStatus(403);
        $inactiveResponse->assertJsonFragment(['message' => __('messages.auth.account_inactive')]);
    }

    public function test_broadcasting_auth_allows_active_user(): void
    {
        Sanctum::actingAs($this->customer);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-users.'.$this->customer->id,
            'socket_id' => '1234.5678',
        ]);

        $response->assertStatus(200);
    }
}
