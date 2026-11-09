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
            'cidr' => '192.168.100.0/24',
            'first_usable' => '192.168.100.2',
            'last_usable' => '192.168.100.254',
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
            'ipv4_address' => '192.168.100.42',
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('192.168.100.42'),
            'ip_string' => '192.168.100.42',
            'virtual_machine_id' => $container->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:19:68:10:42',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $spec = $this->builder->build($container, $allocation);

        $this->assertEquals('container', $spec['type']);
        $this->assertEquals('routed', $spec['devices']['eth0']['nictype']);
        $this->assertEquals('192.168.100.42', $spec['devices']['eth0']['ipv4.address']);
        $this->assertEquals('02:54:19:68:10:42', $spec['devices']['eth0']['hwaddr']);

        // Containers configure networking in-kernel; cloud-init.network-config should NOT be present
        $this->assertArrayNotHasKey('cloud-init.network-config', $spec['config']);
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
}
