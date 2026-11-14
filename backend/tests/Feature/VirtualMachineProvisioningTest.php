<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\States\Vm\Failed;
use App\States\Vm\Running;
use App\States\Vm\Suspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VirtualMachineProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private IpPool $pool;

    private Host $host;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->user = User::factory()->create();

        UserQuota::create([
            'user_id' => $this->user->id,
            'max_vms' => 2,
            'max_cpu_cores' => 4,
            'max_ram_mb' => 4096,
            'max_storage_gb' => 80,
            'max_ips' => 2,
        ]);

        RateLimiter::clear('vm-actions:'.$this->user->id);

        $this->host = Host::create([
            'name' => 'test-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $this->pool = IpPool::create([
            'name' => 'Test Pool',
            'subnet' => '10.0.0.0/24',
            'is_public' => true,
        ]);
    }

    public function test_user_can_provision_virtual_machine_within_quota(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'web-server-01',
                'instance_type' => 'virtual-machine',
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 40,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('virtual_machine.name', 'web-server-01');

        $this->assertDatabaseHas('virtual_machines', [
            'name' => 'web-server-01',
            'user_id' => $this->user->id,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 40,
        ]);
    }

    public function test_provisioning_is_rejected_when_quota_is_exceeded(): void
    {
        // First VM consumes 2 cores, 2048 MB RAM
        $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'vm-one',
                'cpu_cores' => 2,
                'ram_mb' => 2048,
                'disk_gb' => 40,
                'os_image' => 'images:debian/12',
            ])
            ->assertStatus(202);

        // Second VM attempts 4 cores (Total 6 > 4 max_cpu_cores)
        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'vm-two',
                'cpu_cores' => 4,
                'ram_mb' => 1024,
                'disk_gb' => 20,
                'os_image' => 'images:debian/12',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['cpu_cores']);
    }

    public function test_user_can_execute_actions_on_virtual_machine(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'action-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        // Stop VM
        $stopResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'stop',
            ]);

        $stopResponse->assertStatus(200);
        $this->assertEquals('stopped', $vm->fresh()->status::$name);

        // Start VM
        $startResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'start',
            ]);

        $startResponse->assertStatus(200);
        $this->assertEquals('running', $vm->fresh()->status::$name);
    }

    public function test_user_can_suspend_and_resume_virtual_machine(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'suspendable-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        // Suspend VM
        $suspendResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'suspend',
            ]);

        $suspendResponse->assertStatus(200);
        $this->assertEquals('suspended', $vm->fresh()->status::$name);

        // Resume / Start VM from suspended state
        $resumeResponse = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'start',
            ]);

        $resumeResponse->assertStatus(200);
        $this->assertEquals('running', $vm->fresh()->status::$name);
    }

    public function test_infinite_quota_allows_unlimited_provisioning(): void
    {
        // Set user quotas to -1 (infinite)
        $this->user->quota()->update([
            'max_vms' => -1,
            'max_cpu_cores' => -1,
            'max_ram_mb' => -1,
            'max_storage_gb' => -1,
            'max_ips' => -1,
        ]);

        // Provision 3 VMs without being blocked by previous limit of 2 VMs
        for ($i = 1; $i <= 3; $i++) {
            $response = $this->actingAs($this->user)
                ->postJson('/api/vms', [
                    'name' => sprintf('infinite-vm-%d', $i),
                    'cpu_cores' => 8,
                    'ram_mb' => 8192,
                    'disk_gb' => 100,
                    'os_image' => 'images:ubuntu/24.04',
                ]);

            $response->assertStatus(202);
        }

        $this->assertEquals(3, VirtualMachine::where('user_id', $this->user->id)->count());
    }

    public function test_console_route_returns_404(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'console-vm',
            'incus_project' => 'user-1',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(sprintf('/api/vms/%d/console', $vm->id));

        $response->assertStatus(404);
    }

    public function test_user_cannot_provision_duplicate_vm_name_on_same_host(): void
    {
        $host = Host::firstOrFail();

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $host->id,
            'name' => 'unique-test-vm',
            'incus_project' => 'proj-test',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'unique-test-vm',
                'host_id' => $host->id,
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name']);
    }

    public function test_user_can_provision_same_vm_name_on_different_host(): void
    {
        Queue::fake();

        $host1 = Host::firstOrFail();
        $host2 = Host::create([
            'name' => 'test-node-2',
            'endpoint_url' => 'https://127.0.0.2:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 64,
            'total_ram_mb' => 131072,
            'total_storage_gb' => 4000,
            'capacity_source' => 'discovered',
            'capacity_synced_at' => now(),
        ]);

        $pool2 = IpPool::create([
            'name' => 'Test Pool 2',
            'subnet' => '10.0.1.0/24',
            'host_id' => $host2->id,
            'is_public' => true,
        ]);

        VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $host1->id,
            'name' => 'shared-name-vm',
            'incus_project' => 'proj-test',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/vms', [
                'name' => 'shared-name-vm',
                'host_id' => $host2->id,
                'cpu_cores' => 1,
                'ram_mb' => 1024,
                'disk_gb' => 10,
                'os_image' => 'images:ubuntu/24.04',
            ]);

        $response->assertStatus(202);
        $this->assertEquals(2, VirtualMachine::where('user_id', $this->user->id)->where('name', 'shared-name-vm')->count());
    }

    public function test_user_can_delete_virtual_machine_and_atomically_release_ip_address(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'to-delete-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        /** @var IpAllocation $ip */
        $ip = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'address' => inet_pton('192.168.1.55'),
            'ip_string' => '192.168.1.55',
            'status' => 'allocated',
            'user_id' => $this->user->id,
            'virtual_machine_id' => $vm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'delete',
            ]);

        $response->assertStatus(200);

        // VM status transitions to deleted
        $this->assertEquals('deleted', $vm->fresh()->status::$name);

        // IP address is atomically released into cooling quarantine
        $freshIp = $ip->fresh();
        $this->assertEquals('cooling', $freshIp->status);
        $this->assertNull($freshIp->virtual_machine_id);
    }

    public function test_force_delete_vm_releases_ip_resources(): void
    {
        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'failed-vm-force-delete',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Failed::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        /** @var IpAllocation $ip */
        $ip = IpAllocation::create([
            'pool_id' => $this->pool->id,
            'address' => inet_pton('192.168.1.99'),
            'ip_string' => '192.168.1.99',
            'status' => 'allocated',
            'user_id' => $this->user->id,
            'virtual_machine_id' => $vm->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(sprintf('/api/vms/%d/action', $vm->id), [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(200);
        $this->assertEquals('deleted', $vm->fresh()->status::$name);
        $this->assertEquals('cooling', $ip->fresh()->status);
        $this->assertNull($ip->fresh()->virtual_machine_id);
    }

    public function test_unauthorized_user_cannot_view_or_action_foreign_vm(): void
    {
        $otherUser = User::factory()->create();

        /** @var VirtualMachine $vm */
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'secret-vm',
            'incus_project' => 'user-1',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // Unauthorized user attempts show
        $this->actingAs($otherUser)
            ->getJson("/api/vms/{$vm->id}")
            ->assertStatus(403);

        // Unauthorized user attempts action
        $this->actingAs($otherUser)
            ->postJson("/api/vms/{$vm->id}/action", [
                'action' => 'stop',
                'force' => false,
            ])
            ->assertStatus(403);
    }
}
