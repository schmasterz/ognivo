<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\TenantHostResource;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeRedis;
use Tests\TestCase;

final class VmActionHostReachabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $tenant;

    private User $admin;

    private Host $unreachableHost;

    private FakeRedis $fakeRedis;

    protected function setUp(): void
    {
        parent::setUp();

        HypervisorManager::resetFake();

        $this->fakeRedis = new FakeRedis;
        Redis::swap($this->fakeRedis);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->withoutMiddleware(ThrottleRequests::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('admin');

        $this->tenant = User::factory()->create(['status' => 'active']);
        $this->tenant->assignRole('customer');

        $this->unreachableHost = Host::create([
            'name' => 'unreachable-node-01',
            'endpoint_url' => 'https://192.0.2.99:8443',
            'driver' => 'fake',
            'status' => 'unreachable',
            'is_active' => true,
        ]);
    }

    public function test_tenant_host_resource_exposes_status(): void
    {
        $resource = new TenantHostResource($this->unreachableHost);
        $data = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('status', $data);
        $this->assertSame('unreachable', $data['status']);
        $this->assertSame($this->unreachableHost->id, $data['id']);
        $this->assertSame('unreachable-node-01', $data['name']);
        $this->assertSame('fake', $data['driver']);
        $this->assertTrue($data['is_active']);
    }

    public function test_vm_action_start_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-stopped',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Stopped::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'start',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }

    public function test_vm_action_stop_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-running',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'stop',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }

    public function test_vm_console_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-console',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->tenant)
            ->withHeader('Origin', 'http://localhost')
            ->withSession([
                'login_at' => now()->timestamp,
                'last_activity_at' => now()->timestamp,
            ])
            ->postJson("/api/v1/vms/{$vm->id}/console", [
                'type' => 'console',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }

    public function test_admin_force_delete_allowed_when_host_is_unreachable(): void
    {
        Queue::fake();

        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-delete',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(200);
    }

    public function test_tenant_delete_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-tenant-del',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/action", [
                'action' => 'delete',
                'force' => true,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }

    public function test_vm_snapshot_create_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-snap-create',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/snapshots", [
                'name' => 'snap-fail-host',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }

    public function test_vm_snapshot_restore_blocked_when_host_is_unreachable(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->tenant->id,
            'host_id' => $this->unreachableHost->id,
            'name' => 'unreachable-test-vm-snap-restore',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu:24.04',
        ]);

        $snapshot = VmSnapshot::create([
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->tenant->id,
            'name' => 'snap-ready-restore',
            'status' => 'ready',
            'source' => 'manual',
            'size_bytes' => 1024,
        ]);

        $response = $this->actingAs($this->tenant)
            ->postJson("/api/v1/vms/{$vm->id}/snapshots/{$snapshot->id}/restore");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ]);
    }
}
