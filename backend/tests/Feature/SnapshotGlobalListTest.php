<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\States\Vm\Running;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SnapshotGlobalListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    }

    private function createHost(): Host
    {
        return Host::create([
            'name' => 'test-node-01',
            'region' => 'eu-central-1',
            'endpoint_url' => 'https://test-node.local:8443',
            'server_certificate' => 'cert',
            'server_fingerprint' => 'fingerprint',
            'driver' => 'fake',
            'token' => 'token',
            'status' => 'ok',
            'is_active' => true,
        ]);
    }

    private function createVm(User $user, Host $host, string $name): VirtualMachine
    {
        return VirtualMachine::create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'name' => $name,
            'incus_project' => 'project-'.$user->id,
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);
    }

    public function test_tenant_only_sees_their_own_snapshots_in_global_list(): void
    {
        $host = $this->createHost();
        $user1 = User::factory()->create(['status' => 'active']);
        $user1->assignRole('customer');
        $user2 = User::factory()->create(['status' => 'active']);
        $user2->assignRole('customer');

        $vm1 = $this->createVm($user1, $host, 'vm-one');
        $vm2 = $this->createVm($user2, $host, 'vm-two');

        VmSnapshot::create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $user1->id,
            'name' => 'user1-snap',
            'status' => 'ready',
            'source' => 'manual',
            'stateful' => false,
        ]);

        VmSnapshot::create([
            'virtual_machine_id' => $vm2->id,
            'user_id' => $user2->id,
            'name' => 'user2-snap',
            'status' => 'ready',
            'source' => 'manual',
            'stateful' => false,
        ]);

        $response = $this->actingAs($user1)->getJson('/api/snapshots');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'user1-snap')
            ->assertJsonPath('data.0.virtual_machine.name', 'vm-one');
    }

    public function test_admin_can_filter_snapshots_across_tenants(): void
    {
        $host = $this->createHost();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        $user1 = User::factory()->create(['status' => 'active']);
        $user1->assignRole('customer');

        $vm1 = $this->createVm($user1, $host, 'target-vm');

        VmSnapshot::create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $user1->id,
            'name' => 'snap-alpha',
            'status' => 'ready',
            'source' => 'manual',
            'stateful' => false,
        ]);

        VmSnapshot::create([
            'virtual_machine_id' => $vm1->id,
            'user_id' => $user1->id,
            'name' => 'snap-beta',
            'status' => 'creating',
            'source' => 'manual',
            'stateful' => false,
        ]);

        $response = $this->actingAs($admin)->getJson('/api/snapshots?status=creating');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'snap-beta');

        $searchResponse = $this->actingAs($admin)->getJson('/api/snapshots?search=alpha');
        $searchResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'snap-alpha');

        $vmSearchResponse = $this->actingAs($admin)->getJson('/api/snapshots?search=target-vm&per_page=1&page=1');
        $vmSearchResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonPath('meta.total', 2);
    }
}
