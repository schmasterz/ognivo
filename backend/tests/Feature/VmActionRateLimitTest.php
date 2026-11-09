<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VmActionRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $otherCustomer;

    private User $admin;

    private VirtualMachine $vm;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'customer']);

        $this->customer = User::factory()->create();
        $this->customer->assignRole('customer');

        $this->otherCustomer = User::factory()->create();
        $this->otherCustomer->assignRole('customer');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $host = Host::create([
            'name' => 'rate-limit-test-host',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $pool = IpPool::create([
            'name' => 'Rate Limit IP Pool',
            'subnet' => '192.168.100.0/24',
            'host_id' => $host->id,
            'is_public' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->customer->id,
            'host_id' => $host->id,
            'name' => 'rate-limit-vm',
            'incus_project' => 'user-'.$this->customer->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);
    }

    public function test_customer_can_perform_actions_within_rate_limit(): void
    {
        Sanctum::actingAs($this->customer);

        // Perform 10 actions (allowed ceiling)
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson("/api/vms/{$this->vm->id}/action", [
                'action' => 'stop',
                'force' => false,
            ]);

            $response->assertStatus(200);
        }
    }

    public function test_customer_receives_429_when_exceeding_rate_limit(): void
    {
        Sanctum::actingAs($this->customer);

        // Exhaust the 10 allowed attempts
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/vms/{$this->vm->id}/action", [
                'action' => 'stop',
                'force' => false,
            ])->assertStatus(200);
        }

        // 11th request must be rejected with 429 Too Many Requests
        $response = $this->postJson("/api/vms/{$this->vm->id}/action", [
            'action' => 'stop',
            'force' => false,
        ]);

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure(['message', 'retry_after']);

        $this->assertStringContainsString('Too many virtual machine', (string) $response->json('message'));
    }

    public function test_admin_is_exempt_from_action_rate_limiting(): void
    {
        Sanctum::actingAs($this->admin);

        // Admin should be able to perform 12 consecutive requests without throttling
        for ($i = 0; $i < 12; $i++) {
            $response = $this->postJson("/api/vms/{$this->vm->id}/action", [
                'action' => 'restart',
                'force' => false,
            ]);

            $response->assertStatus(200);
        }
    }

    public function test_rate_limits_are_isolated_between_different_users(): void
    {
        // 1. Exhaust customer A's limit
        Sanctum::actingAs($this->customer);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/api/vms/{$this->vm->id}/action", [
                'action' => 'stop',
                'force' => false,
            ])->assertStatus(200);
        }

        // Customer A gets 429
        $this->postJson("/api/vms/{$this->vm->id}/action", [
            'action' => 'stop',
            'force' => false,
        ])->assertStatus(429);

        // 2. Create a VM for Customer B
        $otherVm = VirtualMachine::create([
            'user_id' => $this->otherCustomer->id,
            'host_id' => $this->vm->host_id,
            'name' => 'customer-b-vm',
            'incus_project' => 'user-'.$this->otherCustomer->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // Customer B must NOT be throttled
        Sanctum::actingAs($this->otherCustomer);
        $resOther = $this->postJson("/api/vms/{$otherVm->id}/action", [
            'action' => 'stop',
            'force' => false,
        ]);

        $resOther->assertStatus(200);
    }
}
