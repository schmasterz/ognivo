<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class QuotaGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private QuotaGroup $defaultGroup;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->defaultGroup = QuotaGroup::where('is_default', true)->firstOrFail();

        $this->admin = User::factory()->create(['name' => 'Root Admin', 'email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        $this->customer = User::factory()->create([
            'name' => 'Customer Alice',
            'email' => 'alice@test.local',
            'quota_group_id' => $this->defaultGroup->id,
        ]);
        $this->customer->assignRole('customer');

        UserQuota::create([
            'user_id' => $this->customer->id,
            'quota_group_id' => $this->defaultGroup->id,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 1,
        ]);
    }

    /**
     * Send a stateful JSON POST request with Origin and CSRF headers obtained from /sanctum/csrf-cookie.
     *
     * @param  array<string, mixed>  $data
     */
    private function postStatefulJson(string $uri, array $data = []): TestResponse
    {
        $csrfResponse = $this->withHeader('Origin', 'http://localhost')
            ->getJson('/sanctum/csrf-cookie');
        $csrfResponse->assertNoContent();

        $xsrfCookie = $csrfResponse->getCookie('XSRF-TOKEN', false);
        $xsrfToken = $xsrfCookie !== null ? $xsrfCookie->getValue() : '';

        $sessionCookieName = (string) config('session.cookie');
        $sessionCookie = $csrfResponse->getCookie($sessionCookieName, false);

        $request = $this->withHeaders([
            'Origin' => 'http://localhost',
            'X-XSRF-TOKEN' => $xsrfToken,
        ]);

        if ($sessionCookie !== null) {
            $request = $request->withCredentials()
                ->withUnencryptedCookie($sessionCookieName, $sessionCookie->getValue());
        }

        return $request->postJson($uri, $data);
    }

    public function test_user_registration_automatically_assigns_default_quota_group(): void
    {
        $response = $this->postStatefulJson('/api/v1/register', [
            'name' => 'New Customer',
            'email' => 'newcustomer@test.local',
            'password' => 'Password123!@#',
        ]);

        $response->assertStatus(201);
        $response->assertJsonMissingPath('token');
        $userId = (int) $response->json('user.id');

        /** @var User $newUser */
        $newUser = User::with(['quota', 'quotaGroup'])->findOrFail($userId);

        $this->assertEquals($this->defaultGroup->id, $newUser->quota_group_id);
        $this->assertNotNull($newUser->quota);
        $this->assertEquals($this->defaultGroup->id, $newUser->quota->quota_group_id);
        $this->assertEquals(2, $newUser->quota->max_vms);
        $this->assertEquals(4, $newUser->quota->max_cpu_cores);
    }

    public function test_admin_can_create_quota_group_and_set_as_default(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/admin/quota-groups', [
                'name' => 'High Power Plus',
                'description' => 'High resource quota tier',
                'is_default' => true,
                'max_vms' => 10,
                'max_cpu_cores' => 32,
                'max_ram_mb' => 65536,
                'max_storage_gb' => 1000,
                'max_ips' => 8,
            ]);

        $response->assertStatus(201);
        $groupId = (int) $response->json('quota_group.id');

        $this->assertDatabaseHas('quota_groups', [
            'id' => $groupId,
            'name' => 'High Power Plus',
            'is_default' => true,
        ]);

        // Previous default group must be unset
        $this->assertFalse($this->defaultGroup->fresh()->is_default);
    }

    public function test_admin_can_update_quota_group_and_sync_all_assigned_users(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson(sprintf('/api/v1/admin/quota-groups/%d', $this->defaultGroup->id), [
                'name' => 'Standard Default Updated',
                'is_default' => true,
                'max_vms' => 5,
                'max_cpu_cores' => 12,
                'max_ram_mb' => 16384,
                'max_storage_gb' => 250,
                'max_ips' => 3,
            ]);

        $response->assertStatus(200);

        // Verify customer quota was synchronized
        $this->customer->quota->refresh();
        $this->assertEquals(5, $this->customer->quota->max_vms);
        $this->assertEquals(12, $this->customer->quota->max_cpu_cores);
        $this->assertEquals(16384, $this->customer->quota->max_ram_mb);
        $this->assertEquals(250, $this->customer->quota->max_storage_gb);
        $this->assertEquals(3, $this->customer->quota->max_ips);
    }

    public function test_admin_cannot_delete_default_quota_group(): void
    {
        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/quota-groups/%d', $this->defaultGroup->id));

        $response->assertStatus(422);
        $this->assertDatabaseHas('quota_groups', ['id' => $this->defaultGroup->id]);
    }

    public function test_admin_can_assign_quota_group_to_user(): void
    {
        /** @var QuotaGroup $dedicatedGroup */
        $dedicatedGroup = QuotaGroup::create([
            'name' => 'Dedicated Plan',
            'is_default' => false,
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
            'max_ips' => -1,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(sprintf('/api/v1/admin/users/%d/assign-quota-group', $this->customer->id), [
                'quota_group_id' => $dedicatedGroup->id,
            ]);

        $response->assertStatus(200);

        $this->customer->refresh();
        $this->assertEquals($dedicatedGroup->id, $this->customer->quota_group_id);
        $this->assertEquals(-1, $this->customer->quota->max_vms);
        $this->assertEquals(-1, $this->customer->quota->max_cpu_cores);
    }

    public function test_admin_can_delete_non_default_group_and_reassign_users_to_default(): void
    {
        /** @var QuotaGroup $secondaryGroup */
        $secondaryGroup = QuotaGroup::create([
            'name' => 'Secondary Tier',
            'is_default' => false,
            'max_vms' => 4,
            'max_cpu_cores' => 8,
            'max_ram_mb' => 8192,
            'max_storage_gb' => 100,
            'max_ips' => 2,
        ]);

        $this->customer->update(['quota_group_id' => $secondaryGroup->id]);
        $this->customer->quota()->update(['quota_group_id' => $secondaryGroup->id]);

        $response = $this->actingAs($this->admin)
            ->deleteJson(sprintf('/api/v1/admin/quota-groups/%d', $secondaryGroup->id));

        $response->assertStatus(200);
        $this->assertDatabaseMissing('quota_groups', ['id' => $secondaryGroup->id]);

        // Customer must be reassigned to default group
        $this->customer->refresh();
        $this->assertEquals($this->defaultGroup->id, $this->customer->quota_group_id);
        $this->assertEquals($this->defaultGroup->id, $this->customer->quota->quota_group_id);
    }
}
