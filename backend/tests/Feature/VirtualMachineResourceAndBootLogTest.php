<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VirtualMachineResourceAndBootLogTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;

    private User $otherTenant;

    private User $admin;

    private Host $host;

    private IpPool $pool;

    private VirtualMachine $vm;

    private IpAllocation $allocation;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->admin = User::factory()->create(['email' => 'admin@test.local']);
        $this->admin->assignRole('admin');

        $this->tenant = User::factory()->create(['email' => 'tenant@test.local']);
        $this->tenant->assignRole('customer');

        $this->otherTenant = User::factory()->create(['email' => 'other@test.local']);
        $this->otherTenant->assignRole('customer');

        RateLimiter::clear('vm-actions:'.$this->tenant->id);
        RateLimiter::clear('vm-actions:'.$this->admin->id);

        $this->host = Host::create([
            'name' => 'node-fra-01',
            'endpoint_url' => 'https://192.0.2.10:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $this->pool = IpPool::create([
            'name' => 'Frankfurt Public',
            'host_id' => $this->host->id,
            'cidr' => '198.51.100.0/24',
            'gateway' => '198.51.100.1',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
            'network_mode' => 'routed',
            'is_public' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->host->id,
            'name' => 'app-vm-01',
            'incus_project' => 'user-'.$this->tenant->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
            'ipv4_address' => '198.51.100.15',
            'network_status' => 'observed',
        ]);

        $this->allocation = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'user_id' => $this->tenant->id,
            'virtual_machine_id' => $this->vm->id,
            'address' => inet_pton('198.51.100.15'),
            'ip_string' => '198.51.100.15',
            'mac_address' => '00:16:3e:aa:bb:cc',
            'status' => 'allocated',
        ]);
    }

    public function test_vm_resource_shape_for_tenant(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/v1/vms/{$this->vm->id}");

        $response->assertStatus(200);

        // Assert ip_addresses is gone
        $response->assertJsonMissingPath('data.ip_addresses');
        $response->assertJsonMissingPath('ip_addresses');

        // Assert contract for network block
        $data = $response->json();
        $vmData = $data['data'] ?? $data;

        $this->assertArrayHasKey('network', $vmData);
        $this->assertEquals([
            'primary' => [
                'address' => '198.51.100.15',
                'family' => 4,
                'status' => 'observed',
                'mac_address' => '00:16:3e:aa:bb:cc',
            ],
            'settings' => [
                'prefix' => 24,
                'gateway' => '198.51.100.1',
                'dns' => ['1.1.1.1', '8.8.8.8'],
                'mode' => 'routed',
            ],
            'observed_at' => $this->vm->updated_at?->toISOString(),
        ], $vmData['network']);

        // Assert mac_address is populated from primary allocation
        $this->assertSame('00:16:3e:aa:bb:cc', $vmData['mac_address']);

        // Tenant does not get internal host secrets
        $this->assertArrayNotHasKey('endpoint_url', $vmData['host'] ?? []);
    }

    public function test_vm_resource_shape_for_admin(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/vms/{$this->vm->id}");

        $response->assertStatus(200);

        $data = $response->json();
        $vmData = $data['data'] ?? $data;

        $this->assertArrayHasKey('network', $vmData);
        $this->assertEquals([
            'primary' => [
                'address' => '198.51.100.15',
                'family' => 4,
                'status' => 'observed',
                'mac_address' => '00:16:3e:aa:bb:cc',
            ],
            'settings' => [
                'prefix' => 24,
                'gateway' => '198.51.100.1',
                'dns' => ['1.1.1.1', '8.8.8.8'],
                'mode' => 'routed',
            ],
            'observed_at' => $this->vm->updated_at?->toISOString(),
        ], $vmData['network']);

        $this->assertSame('00:16:3e:aa:bb:cc', $vmData['mac_address']);
        $this->assertArrayHasKey('endpoint_url', $vmData['host']);
    }

    public function test_console_route_returns_404(): void
    {
        $response = $this->actingAs($this->tenant)
            ->getJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(404);
    }

    public function test_boot_log_is_owner_only(): void
    {
        // 1. Owner can access boot log
        $ownerRes = $this->actingAs($this->tenant)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $ownerRes->assertStatus(200)
            ->assertJsonStructure(['virtual_machine_id', 'name', 'output']);

        // 2. Foreign tenant is denied (403)
        $foreignRes = $this->actingAs($this->otherTenant)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $foreignRes->assertStatus(403);

        // 3. Admin is denied (403) by viewConsole policy privacy constraint
        $adminRes = $this->actingAs($this->admin)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $adminRes->assertStatus(403);
    }

    public function test_admin_can_view_boot_log_for_their_own_vm(): void
    {
        $adminVm = VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->host->id,
            'name' => 'admin-console-log-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/v1/vms/{$adminVm->id}/console-log");

        $response->assertStatus(200)
            ->assertJsonStructure(['virtual_machine_id', 'name', 'output']);
        $this->assertSame($adminVm->id, $response->json('virtual_machine_id'));
    }

    public function test_boot_log_emits_structured_log_line(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('console.log.viewed', \Mockery::on(function (array $context): bool {
                return ($context['user_id'] ?? null) === $this->tenant->id
                    && ($context['virtual_machine_id'] ?? null) === $this->vm->id
                    && isset($context['bytes_returned'])
                    && is_int($context['bytes_returned'])
                    && $context['bytes_returned'] > 0;
            }));

        $res = $this->actingAs($this->tenant)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");

        $res->assertStatus(200);
    }

    public function test_boot_log_is_throttled_to_six_per_minute(): void
    {
        // Clear any previous attempts
        RateLimiter::clear($this->getThrottleKey($this->tenant));

        // 6 successful requests
        for ($i = 0; $i < 6; $i++) {
            $res = $this->actingAs($this->tenant)
                ->getJson("/api/v1/vms/{$this->vm->id}/console-log");
            $this->assertSame(200, $res->status(), "Request #{$i} should succeed with 200");
        }

        // 7th request exceeds throttle (6/min)
        $rateLimitedRes = $this->actingAs($this->tenant)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $this->assertSame(429, $rateLimitedRes->status());
    }

    private function getThrottleKey(User $user): string
    {
        return sha1('6|1|'.(string) $user->getAuthIdentifier());
    }
}
