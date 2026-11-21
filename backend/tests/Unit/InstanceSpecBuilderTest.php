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
use DomainException;
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
        $this->assertArrayNotHasKey('migration.stateful', $spec['config']);
        $this->assertArrayNotHasKey('cloud-init.vendor-data', $spec['config']);
        $this->assertEquals("#cloud-config\npackage_update: true", $spec['config']['cloud-init.user-data']);

        // Devices
        $this->assertArrayHasKey('devices', $spec);
        $this->assertEquals('disk', $spec['devices']['root']['type']);
        $this->assertEquals('custom-pool', $spec['devices']['root']['pool']);
        $this->assertEquals('50GB', $spec['devices']['root']['size']);
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $spec['devices']['agent']);

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
        $this->assertStringContainsString('match:', $netplanYaml);
        $this->assertStringContainsString('macaddress: "02:54:cb:00:71:19"', $netplanYaml);
        $this->assertStringContainsString('set-name: eth0', $netplanYaml);
        $this->assertStringContainsString('- 203.0.113.25/32', $netplanYaml);
        $this->assertStringContainsString('to: 0.0.0.0/0', $netplanYaml);
        $this->assertStringContainsString('via: 169.254.0.1', $netplanYaml);
        $this->assertStringContainsString('on-link: true', $netplanYaml);
        $this->assertStringNotContainsString('169.254.0.1/32', $netplanYaml);
        $this->assertStringContainsString('nameservers:', $netplanYaml);
        $this->assertStringContainsString('- 1.1.1.1', $netplanYaml);
        $this->assertStringContainsString('- 1.0.0.1', $netplanYaml);
    }

    public function test_build_container_spec_has_routed_nic_with_cloud_init_network_config_for_dns_and_routes(): void
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
        $this->assertArrayNotHasKey('agent', $spec['devices']);
        $this->assertEquals('routed', $spec['devices']['eth0']['nictype']);
        $this->assertEquals('198.51.100.42', $spec['devices']['eth0']['ipv4.address']);
        $this->assertEquals('02:54:c6:33:64:2a', $spec['devices']['eth0']['hwaddr']);

        // Containers in routed mode receive cloud-init.network-config for DNS nameservers and link-local default route
        $this->assertArrayHasKey('cloud-init.network-config', $spec['config']);
        $netplanYaml = $spec['config']['cloud-init.network-config'];
        $this->assertStringNotContainsString('match:', $netplanYaml);
        $this->assertStringNotContainsString('set-name: eth0', $netplanYaml);
        $this->assertStringContainsString('addresses:', $netplanYaml);
        $this->assertStringContainsString('to: 0.0.0.0/0', $netplanYaml);
        $this->assertStringContainsString('via: 169.254.0.1', $netplanYaml);
        $this->assertStringContainsString('on-link: true', $netplanYaml);
        $this->assertStringNotContainsString('169.254.0.1/32', $netplanYaml);
        $this->assertStringContainsString('nameservers:', $netplanYaml);
        $this->assertStringContainsString('1.1.1.1', $netplanYaml);
        $this->assertStringContainsString('8.8.8.8', $netplanYaml);
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
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $payload['devices']['agent']);

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
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $bridgedSpec['devices']['agent']);
        $this->assertSame('bridged', $bridgedSpec['devices']['eth0']['nictype']);
        $this->assertSame('incusbr0', $bridgedSpec['devices']['eth0']['parent']);
        $this->assertSame('02:54:0a:f1:4f:32', $bridgedSpec['devices']['eth0']['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $bridgedSpec['devices']['eth0']);
        $bridgedNetplan = $bridgedSpec['config']['cloud-init.network-config'];
        $this->assertStringContainsString('match:', $bridgedNetplan);
        $this->assertStringContainsString('macaddress: "02:54:0a:f1:4f:32"', $bridgedNetplan);
        $this->assertStringContainsString('set-name: eth0', $bridgedNetplan);
        $this->assertStringContainsString('- 10.241.79.50/24', $bridgedNetplan);
        $this->assertStringContainsString('to: 0.0.0.0/0', $bridgedNetplan);
        $this->assertStringContainsString('via: 10.241.79.1', $bridgedNetplan);
        $this->assertStringContainsString('nameservers:', $bridgedNetplan);
        $this->assertSame('ubuntu/24.04/cloud', $bridgedSpec['source']['alias']);

        // Macvlan pool with configured parent_interface 'eno1' present on host ['docker0', 'eno1', 'incusbr0']
        $macvlanPool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Macvlan LAN Pool',
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.10',
            'last_usable' => '198.51.100.200',
            'gateway' => '198.51.100.1',
            'network_mode' => 'macvlan',
            'parent_interface' => 'eno1',
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
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $macvlanSpec['devices']['agent']);
        $this->assertSame('macvlan', $macvlanSpec['devices']['eth0']['nictype']);
        $this->assertSame('eno1', $macvlanSpec['devices']['eth0']['parent']);
        $this->assertSame('ubuntu/24.04/cloud', $macvlanSpec['source']['alias']);
        $macvlanNetplan = $macvlanSpec['config']['cloud-init.network-config'];
        $this->assertStringContainsString('match:', $macvlanNetplan);
        $this->assertStringContainsString('macaddress: "02:54:c6:33:64:14"', $macvlanNetplan);
        $this->assertStringContainsString('set-name: eth0', $macvlanNetplan);
        $this->assertStringContainsString('- 198.51.100.20/24', $macvlanNetplan);
        $this->assertStringContainsString('to: 0.0.0.0/0', $macvlanNetplan);
        $this->assertStringContainsString('via: 198.51.100.1', $macvlanNetplan);
        $this->assertStringContainsString('nameservers:', $macvlanNetplan);
    }

    public function test_build_throws_when_configured_parent_interface_is_missing_on_host(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Macvlan Missing Parent Pool',
            'cidr' => '198.51.100.0/24',
            'first_usable' => '198.51.100.10',
            'last_usable' => '198.51.100.200',
            'gateway' => '198.51.100.1',
            'network_mode' => 'macvlan',
            'parent_interface' => 'eth0',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-missing-iface',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '198.51.100.20',
        ]);

        $alloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('198.51.100.20'),
            'ip_string' => '198.51.100.20',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:c6:33:64:14',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('eth0');
        $this->expectExceptionMessage('macvlan');
        $this->expectExceptionMessage('eno1, incusbr0');

        $this->builder->build($vm, $alloc, 'default', null, null, null, ['eno1', 'incusbr0']);
    }

    public function test_build_throws_when_non_manual_pool_has_empty_parent_interface(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Empty Parent Pool',
            'cidr' => '10.20.0.0/24',
            'first_usable' => '10.20.0.10',
            'last_usable' => '10.20.0.50',
            'gateway' => '10.20.0.1',
            'network_mode' => 'bridged',
            'parent_interface' => '',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-empty-iface',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'status' => 'provisioning',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '10.20.0.10',
        ]);

        $alloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('10.20.0.10'),
            'ip_string' => '10.20.0.10',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('parent_interface');

        $this->builder->build($vm, $alloc);
    }

    public function test_manual_pool_never_needs_parent_interface(): void
    {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Manual Empty Parent Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'manual',
            'parent_interface' => '',
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-manual-empty-iface',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => '203.0.113.10',
        ]);

        $alloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.10'),
            'ip_string' => '203.0.113.10',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $spec = $this->builder->build($vm, $alloc, 'default', null, null, null, ['eno1']);

        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $spec['devices']['agent']);
        $this->assertArrayNotHasKey('eth0', $spec['devices']);
        $this->assertArrayNotHasKey('cloud-init.network-config', $spec['config']);
    }

    public function test_resolve_parent_interface_returns_configured_value_when_host_networks_not_queried(): void
    {
        $this->assertSame('br-lan', $this->builder->resolveParentInterface('br-lan', 'bridged', null));
        $this->assertSame('br-lan', $this->builder->resolveParentInterface('br-lan', 'bridged', []));
    }

    public function test_build_routed_spec_differentiates_routes_by_os_image(): void
    {
        $debianImage = Image::firstOrCreate(
            ['name' => 'debian/12'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $debianImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $almaImage = Image::firstOrCreate(
            ['name' => 'almalinux/9'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $almaImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'OS Route Differentiation Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'routed',
            'parent_interface' => 'bond0',
            'dns_servers' => ['1.1.1.1'],
        ]);

        $debianVm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'srv-debian-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'debian/12',
            'ipv4_address' => '203.0.113.31',
        ]);

        $debianAlloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.31'),
            'ip_string' => '203.0.113.31',
            'virtual_machine_id' => $debianVm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:1f',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $debianSpec = $this->builder->build($debianVm, $debianAlloc);
        $debianNetplan = $debianSpec['config']['cloud-init.network-config'];
        $this->assertStringContainsString('- to: 169.254.0.1/32', $debianNetplan);
        $this->assertStringContainsString("- to: 0.0.0.0/0\n          via: 169.254.0.1", $debianNetplan);
        $this->assertStringNotContainsString('on-link: true', $debianNetplan);

        $almaVm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'srv-alma-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'almalinux/9',
            'ipv4_address' => '203.0.113.32',
        ]);

        $almaAlloc = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.32'),
            'ip_string' => '203.0.113.32',
            'virtual_machine_id' => $almaVm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:20',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $almaSpec = $this->builder->build($almaVm, $almaAlloc);
        $almaNetplan = $almaSpec['config']['cloud-init.network-config'];
        $this->assertStringContainsString("- to: 0.0.0.0/0\n          via: 169.254.0.1", $almaNetplan);
        $this->assertStringNotContainsString('on-link: true', $almaNetplan);
        $this->assertStringNotContainsString('169.254.0.1/32', $almaNetplan);
    }

    public function test_build_alpine_spec_injects_vendor_data_for_resolv_conf(): void
    {
        $alpineImage = Image::firstOrCreate(
            ['name' => 'alpine/3.21'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $alpineImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Alpine Routed Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'routed',
            'parent_interface' => 'bond0',
            'dns_servers' => ['1.1.1.1', '8.8.8.8'],
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'srv-alpine-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'alpine/3.21',
            'ipv4_address' => '203.0.113.45',
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.45'),
            'ip_string' => '203.0.113.45',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:2d',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $spec = $this->builder->build($vm, $allocation);

        $this->assertArrayHasKey('cloud-init.vendor-data', $spec['config']);
        $this->assertStringContainsString('manage_resolv_conf: true', $spec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('- 1.1.1.1', $spec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('- 8.8.8.8', $spec['config']['cloud-init.vendor-data']);
    }

    public function test_build_rhel_container_injects_vendor_data_for_resolv_conf_while_vm_does_not(): void
    {
        $rockyImage = Image::firstOrCreate(
            ['name' => 'rockylinux/10'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $rockyImage->hosts()->syncWithoutDetaching([$this->host->id]);

        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => 'Rocky Routed Pool',
            'cidr' => '203.0.113.0/24',
            'first_usable' => '203.0.113.10',
            'last_usable' => '203.0.113.50',
            'network_mode' => 'routed',
            'parent_interface' => 'bond0',
            'dns_servers' => ['1.1.1.1', '9.9.9.9'],
        ]);

        $container = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'ct-rocky-01',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'rockylinux/10',
            'ipv4_address' => '203.0.113.46',
        ]);

        $containerAllocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.46'),
            'ip_string' => '203.0.113.46',
            'virtual_machine_id' => $container->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:2e',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $containerSpec = $this->builder->build($container, $containerAllocation);

        $this->assertArrayHasKey('cloud-init.vendor-data', $containerSpec['config']);
        $this->assertStringContainsString('#cloud-config', $containerSpec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('write_files:', $containerSpec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('path: /etc/resolv.conf', $containerSpec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('nameserver 1.1.1.1', $containerSpec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString('nameserver 9.9.9.9', $containerSpec['config']['cloud-init.vendor-data']);
        $this->assertStringContainsString("runcmd:\n  - nmcli connection up 'cloud-init eth0' || true", $containerSpec['config']['cloud-init.vendor-data']);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-rocky-01',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => 'provisioning',
            'network_status' => 'assigned',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'rockylinux/10',
            'ipv4_address' => '203.0.113.47',
        ]);

        $vmAllocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton('203.0.113.47'),
            'ip_string' => '203.0.113.47',
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => '02:54:cb:00:71:2f',
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        $vmSpec = $this->builder->build($vm, $vmAllocation);

        $this->assertArrayNotHasKey('cloud-init.vendor-data', $vmSpec['config']);
    }

    public function test_resolve_image_alias_appends_cloud_for_single_segment_aliases_like_archlinux(): void
    {
        $archImage = Image::firstOrCreate(
            ['name' => 'archlinux'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );

        $this->assertSame('archlinux/cloud', $this->builder->resolveImageAlias($archImage, true));
        $this->assertSame('archlinux', $this->builder->resolveImageAlias($archImage, false));
        $this->assertSame('ubuntu/24.04/cloud', $this->builder->resolveImageAlias($this->ubuntuImage, true));

        $archCloudImage = Image::firstOrCreate(
            ['name' => 'archlinux/cloud'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );

        $archDefaultImage = Image::firstOrCreate(
            ['name' => 'archlinux/default'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );

        $this->assertSame('archlinux/cloud', $this->builder->resolveImageAlias($archCloudImage, true));
        $this->assertSame('archlinux/default', $this->builder->resolveImageAlias($archDefaultImage, true));
    }
}
