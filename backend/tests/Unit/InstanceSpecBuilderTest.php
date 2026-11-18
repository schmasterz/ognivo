<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\InstanceSpecBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class InstanceSpecBuilderTest extends TestCase
{
    use RefreshDatabase;

    private InstanceSpecBuilder $builder;

    private Host $host;

    private User $user;

    private Image $ubuntuImage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new InstanceSpecBuilder;

        $this->host = Host::create([
            'name' => 'compute-node-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create();

        $this->ubuntuImage = Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $this->ubuntuImage->hosts()->syncWithoutDetaching([$this->host->id]);
    }

    public function test_build_virtual_machine_spec_with_routed_nic_and_netplan_v2(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Routed DC Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'routed',
            'parent_interface' => 'bond0',
            'dns_servers' => ['1.1.1.1', '1.0.0.1'],
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'srv-web-01',
            'incus_project' => 'tenant-uuid-1234',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 4,
            'ram_mb' => 8192,
            'disk_gb' => 50,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '203.0.113.25',
            'cloud_init' => [
                'user-data' => "#cloud-config\npackage_update: true",
            ],
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.25'),
            'ip_string' => '203.0.113.25',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:19',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $spec = $this->builder->build($vm, $allocation, 'custom-pool');

        // Top level metadata
        $this->assertEquals('srv-web-01', $spec['name']);
        $this->assertEquals('virtual-machine', $spec['type']);

        // Limits
        $this->assertEquals('4', $spec['config']['limits.cpu']);
        $this->assertEquals('8192MB', $spec['config']['limits.memory']);
        $this->assertEquals('true', $spec['config']['migration.stateful'] ?? null);
        $this->assertEquals("#cloud-config\npackage_update: true", $spec['config']['cloud-init.user-data']);

        // Devices
        $this->assertArrayHasKey('devices', $spec);
        $this->assertEquals('disk', $spec['devices']['root']['type']);
        $this->assertEquals('custom-pool', $spec['devices']['root']['pool']);
        $this->assertEquals('50GB', $spec['devices']['root']['size']);

        // Routed NIC device
        $eth0 = $spec['devices']['eth0'];
        $this->assertEquals('nic', $eth0['type']);
        $this->assertEquals('routed', $eth0['nictype']);
        $this->assertEquals('bond0', $eth0['parent']);
        $this->assertEquals('203.0.113.25', $eth0['ipv4.address']);
        $this->assertEquals('02:54:cb:00:71:19', $eth0['hwaddr']);

        // Netplan v2 configuration for VM
        $this->assertArrayHasKey('cloud-init.network-config', $spec['config']);
        $netplanYaml = $spec['config']['cloud-init.network-config'];

        $this->assertStringContainsString('version: 2', $netplanYaml);
        $this->assertStringContainsString('macaddress: "02:54:cb:00:71:19"', $netplanYaml);
        $this->assertStringContainsString('- 203.0.113.25/32', $netplanYaml);
        $this->assertStringContainsString('to: default', $netplanYaml);
        $this->assertStringContainsString('via: 169.254.0.1', $netplanYaml);
        $this->assertStringContainsString('on-link: true', $netplanYaml);
        $this->assertStringContainsString('- 1.1.1.1', $netplanYaml);
        $this->assertStringContainsString('- 1.0.0.1', $netplanYaml);
    }

    public function test_build_container_spec_has_routed_nic_without_cloud_init_network_config(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Container Pool',
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.2',
            'last_usable' => '198.51.100.254',
            'network_mode' => 'routed',
            'parent_interface' => 'eth0',
        ]);

        $container = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'lxc-worker',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '198.51.100.42',
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('198.51.100.42'),
            'ip_string' => '198.51.100.42',
            'virtual_machine_id' => $container->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:c6:33:64:2a',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $spec = $this->builder->build($container, $allocation);

        $this->assertEquals('container', $spec['type']);
        $this->assertEquals('routed', $spec['devices']['eth0']['nictype']);
        $this->assertEquals('198.51.100.42', $spec['devices']['eth0']['ipv4.address']);
        $this->assertEquals('02:54:c6:33:64:2a', $spec['devices']['eth0']['hwaddr']);

        // Containers configure networking in-kernel; cloud-init.network-config should NOT be present
        $this->assertArrayNotHasKey('cloud-init.network-config', $spec['config']);
        $this->assertArrayNotHasKey('migration.stateful', $spec['config']);
    }

    public function test_build_virtual_machine_spec_with_manual_pool_produces_no_nic_or_netplan_config(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual DC Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'manual',
            'parent_interface' => 'bond0',
            'dns_servers' => ['1.1.1.1', '1.0.0.1'],
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'manual-vm-01',
            'incus_project' => 'tenant-uuid-1234',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 4096,
            'disk_gb' => 30,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '203.0.113.10',
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.10'),
            'ip_string' => '203.0.113.10',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $payload = $this->builder->build($vm, $allocation);

        // devices must NOT contain eth0
        $this->assertArrayNotHasKey('eth0', $payload['devices']);
        $this->assertArrayHasKey('root', $payload['devices']);

        // config must NOT contain cloud-init.network-config
        $this->assertArrayNotHasKey('cloud-init.network-config', $payload['config']);
    }

    public function test_build_rejects_unapproved_image(): void
    {
        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'evil-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'unapproved/image:latest',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("OS image 'unapproved/image:latest' is not in the approved allowlist");

        $this->builder->build($vm);
    }

    public function test_build_virtual_machine_spec_with_bridged_and_macvlan_modes(): void
    {
        $bridgedPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Bridged LAN Pool',
            'cidr' => '10.241.79.0/24',
            'first_usable' => '10.241.79.10',
            'last_usable' => '10.241.79.200',
            'gateway' => '10.241.79.1',
            'network_mode' => 'bridged',
            'parent_interface' => 'incusbr0',
            'dns_servers' => ['1.1.1.1'],
        ]);

        $vmBridged = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-bridged-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '10.241.79.50',
        ]);

        $allocBridged = IpAllocation::create([
            'pool_id' => $bridgedPool->id,
            'address' => inet_pton('10.241.79.50'),
            'ip_string' => '10.241.79.50',
            'virtual_machine_id' => $vmBridged->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:0a:f1:4f:32',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $bridgedSpec = $this->builder->build($vmBridged, $allocBridged);
        $this->assertSame('bridged', $bridgedSpec['devices']['eth0']['nictype']);
        $this->assertSame('incusbr0', $bridgedSpec['devices']['eth0']['parent']);
        $this->assertSame('02:54:0a:f1:4f:32', $bridgedSpec['devices']['eth0']['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $bridgedSpec['devices']['eth0']);
        $this->assertStringContainsString('- 10.241.79.50/24', $bridgedSpec['config']['cloud-init.network-config']);
        $this->assertStringContainsString('via: 10.241.79.1', $bridgedSpec['config']['cloud-init.network-config']);
        $this->assertSame('ubuntu/24.04/cloud', $bridgedSpec['source']['alias']);

        // Macvlan pool with parent_interface 'eth0' auto-resolved to 'eno1' when host has ['eno1', 'incusbr0']
        $macvlanPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Macvlan LAN Pool',
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.10',
            'last_usable' => '198.51.100.200',
            'gateway' => '198.51.100.1',
            'network_mode' => 'macvlan',
            'parent_interface' => 'eth0',
        ]);

        $vmMacvlan = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-macvlan-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
            'ipv4_address' => '198.51.100.20',
        ]);

        $allocMacvlan = IpAllocation::create([
            'pool_id' => $macvlanPool->id,
            'address' => inet_pton('198.51.100.20'),
            'ip_string' => '198.51.100.20',
            'virtual_machine_id' => $vmMacvlan->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:c6:33:64:14',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $macvlanSpec = $this->builder->build($vmMacvlan, $allocMacvlan, 'default', null, null, null, ['docker0', 'eno1', 'incusbr0']);
        $this->assertSame('macvlan', $macvlanSpec['devices']['eth0']['nictype']);
        $this->assertSame('eno1', $macvlanSpec['devices']['eth0']['parent']);
        $this->assertSame('ubuntu/24.04/cloud', $macvlanSpec['source']['alias']);
        $this->assertStringContainsString('- 198.51.100.20/24', $macvlanSpec['config']['cloud-init.network-config']);
        $this->assertStringContainsString('via: 198.51.100.1', $macvlanSpec['config']['cloud-init.network-config']);
    }
}
