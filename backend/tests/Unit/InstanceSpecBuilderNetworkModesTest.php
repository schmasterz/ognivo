<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\NetworkStatus;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\InstanceSpecBuilder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class InstanceSpecBuilderNetworkModesTest extends TestCase
{
    use RefreshDatabase;

    private InstanceSpecBuilder $builder;

    private Host $host;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new InstanceSpecBuilder;

        $this->host = Host::create([
            'name' => 'compute-node-net-1',
            'endpoint_url' => 'https://127.0.0.1:8443',
            'driver' => 'fake',
            'is_active' => true,
            'total_cpu_cores' => 32,
            'total_ram_mb' => 65536,
            'total_storage_gb' => 1000,
        ]);

        $this->user = User::factory()->create();

        $image = Image::firstOrCreate(
            ['name' => 'ubuntu/24.04'],
            [
                'source' => 'https://images.linuxcontainers.org',
                'protocol' => 'simplestreams',
                'os_type' => 'linux',
                'enabled' => true,
            ]
        );
        $image->hosts()->syncWithoutDetaching([$this->host->id]);
    }

    /**
     * @param  array<string>|null  $dnsServers
     * @return array{0: VirtualMachine, 1: IpAllocation}
     */
    private function createWorkload(
        string $instanceType,
        string $networkMode,
        string $parentInterface,
        string $cidr,
        string $ip,
        ?string $gateway,
        ?array $dnsServers,
        string $mac = '02:54:aa:bb:cc:01',
    ): array {
        $pool = IpPool::create([
            'host_id' => $this->host->id,
            'name' => sprintf('Pool-%s-%s-%s', $networkMode, $instanceType, uniqid()),
            'cidr' => $cidr,
            'first_usable' => $ip,
            'last_usable' => $ip,
            'gateway' => $gateway,
            'network_mode' => $networkMode,
            'parent_interface' => $parentInterface,
            'dns_servers' => $dnsServers,
        ]);

        $vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->host->id,
            'name' => 'vm-'.uniqid(),
            'incus_project' => 'default',
            'instance_type' => $instanceType,
            'status' => 'provisioning',
            'network_status' => NetworkStatus::Assigned,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'ubuntu/24.04',
            'ipv4_address' => $ip,
        ]);

        $allocation = IpAllocation::create([
            'pool_id' => $pool->id,
            'address' => inet_pton($ip),
            'ip_string' => $ip,
            'virtual_machine_id' => $vm->id,
            'user_id' => $this->user->id,
            'mac_address' => $mac,
            'status' => 'allocated',
            'allocated_at' => now(),
        ]);

        return [$vm, $allocation];
    }

    public function test_bridged_container_spec_with_custom_prefix_gateway_and_default_dns(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'container',
            networkMode: 'bridged',
            parentInterface: 'incusbr0',
            cidr: '10.241.79.0/25',
            ip: '10.241.79.50',
            gateway: '10.241.79.1',
            dnsServers: null,
            mac: '02:54:0a:f1:4f:32',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['incusbr0', 'eno1']);

        $this->assertSame('container', $spec['type']);
        $this->assertArrayNotHasKey('migration.stateful', $spec['config']);
        $this->assertArrayNotHasKey('agent', $spec['devices']);

        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('nic', $eth0['type']);
        $this->assertSame('bridged', $eth0['nictype']);
        $this->assertSame('incusbr0', $eth0['parent']);
        $this->assertSame('02:54:0a:f1:4f:32', $eth0['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $eth0);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 10.241.79.50/25
      routes:
        - to: 0.0.0.0/0
          via: 10.241.79.1
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML;

        $netplanYaml = $spec['config']['cloud-init.network-config'];
        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($netplanYaml));
        $this->assertStringNotContainsString('match:', $netplanYaml);
        $this->assertStringNotContainsString('set-name: eth0', $netplanYaml);
        $this->assertStringContainsString('addresses:', $netplanYaml);
        $this->assertStringContainsString('to: 0.0.0.0/0', $netplanYaml);
        $this->assertStringContainsString('nameservers:', $netplanYaml);
        $this->assertSame('ubuntu/24.04/cloud', $spec['source']['alias']);
    }

    public function test_bridged_virtual_machine_spec_with_custom_dns(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'virtual-machine',
            networkMode: 'bridged',
            parentInterface: 'br0',
            cidr: '192.168.100.0/26',
            ip: '192.168.100.20',
            gateway: '192.168.100.1',
            dnsServers: ['9.9.9.9', '8.8.4.4'],
            mac: '02:54:c0:a8:64:14',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['br0']);

        $this->assertSame('virtual-machine', $spec['type']);
        $this->assertArrayNotHasKey('migration.stateful', $spec['config']);
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $spec['devices']['agent']);

        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('bridged', $eth0['nictype']);
        $this->assertSame('br0', $eth0['parent']);
        $this->assertSame('02:54:c0:a8:64:14', $eth0['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $eth0);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:c0:a8:64:14"
      set-name: eth0
      addresses:
        - 192.168.100.20/26
      routes:
        - to: 0.0.0.0/0
          via: 192.168.100.1
      nameservers:
        addresses:
          - 9.9.9.9
          - 8.8.4.4
YAML;

        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($spec['config']['cloud-init.network-config']));
    }

    public function test_macvlan_container_spec_without_gateway_omits_routes_block(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'container',
            networkMode: 'macvlan',
            parentInterface: 'eno1',
            cidr: '198.51.100.0/24',
            ip: '198.51.100.40',
            gateway: null,
            dnsServers: ['1.0.0.1'],
            mac: '02:54:c6:33:64:28',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['eno1']);

        $this->assertSame('container', $spec['type']);
        $this->assertArrayNotHasKey('agent', $spec['devices']);
        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('macvlan', $eth0['nictype']);
        $this->assertSame('eno1', $eth0['parent']);
        $this->assertSame('02:54:c6:33:64:28', $eth0['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $eth0);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.40/24
      nameservers:
        addresses:
          - 1.0.0.1
YAML;

        $netplanYaml = $spec['config']['cloud-init.network-config'];
        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($netplanYaml));
        $this->assertStringNotContainsString('match:', $netplanYaml);
        $this->assertStringNotContainsString('set-name: eth0', $netplanYaml);
        $this->assertStringContainsString('addresses:', $netplanYaml);
        $this->assertStringContainsString('nameservers:', $netplanYaml);
    }

    public function test_macvlan_virtual_machine_spec_with_gateway_and_empty_dns_uses_defaults(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'virtual-machine',
            networkMode: 'macvlan',
            parentInterface: 'bond0.100',
            cidr: '203.0.113.128/25',
            ip: '203.0.113.150',
            gateway: '203.0.113.129',
            dnsServers: [],
            mac: '02:54:cb:00:71:96',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['bond0.100']);

        $this->assertSame('virtual-machine', $spec['type']);
        $this->assertArrayNotHasKey('migration.stateful', $spec['config']);
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $spec['devices']['agent']);

        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('macvlan', $eth0['nictype']);
        $this->assertSame('bond0.100', $eth0['parent']);
        $this->assertSame('02:54:cb:00:71:96', $eth0['hwaddr']);
        $this->assertArrayNotHasKey('ipv4.address', $eth0);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:cb:00:71:96"
      set-name: eth0
      addresses:
        - 203.0.113.150/25
      routes:
        - to: 0.0.0.0/0
          via: 203.0.113.129
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML;

        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($spec['config']['cloud-init.network-config']));
    }

    public function test_routed_container_has_ipv4_address_and_exact_link_local_netplan_yaml(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'container',
            networkMode: 'routed',
            parentInterface: 'eth0',
            cidr: '198.51.100.0/24',
            ip: '198.51.100.77',
            gateway: '198.51.100.1',
            dnsServers: ['9.9.9.9'],
            mac: '02:54:c6:33:64:4d',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['eth0']);

        $this->assertSame('container', $spec['type']);
        $this->assertArrayNotHasKey('agent', $spec['devices']);
        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('routed', $eth0['nictype']);
        $this->assertSame('eth0', $eth0['parent']);
        $this->assertSame('02:54:c6:33:64:4d', $eth0['hwaddr']);
        $this->assertSame('198.51.100.77', $eth0['ipv4.address']);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.77/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 9.9.9.9
YAML;

        $netplanYaml = $spec['config']['cloud-init.network-config'];
        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($netplanYaml));
        $this->assertStringNotContainsString('match:', $netplanYaml);
        $this->assertStringNotContainsString('set-name: eth0', $netplanYaml);
        $this->assertStringContainsString('addresses:', $netplanYaml);
        $this->assertStringContainsString('to: 0.0.0.0/0', $netplanYaml);
        $this->assertStringContainsString('nameservers:', $netplanYaml);
    }

    public function test_routed_virtual_machine_has_ipv4_address_and_exact_link_local_netplan_yaml(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'virtual-machine',
            networkMode: 'routed',
            parentInterface: 'bond0',
            cidr: '203.0.113.0/24',
            ip: '203.0.113.33',
            gateway: null,
            dnsServers: ['1.1.1.1', '9.9.9.9'],
            mac: '02:54:cb:00:71:21',
        );

        $spec = $this->builder->build($vm, $allocation, 'default', ['bond0']);

        $this->assertSame('virtual-machine', $spec['type']);
        $this->assertSame([
            'type' => 'disk',
            'source' => 'agent:config',
        ], $spec['devices']['agent']);
        $eth0 = $spec['devices']['eth0'];
        $this->assertSame('routed', $eth0['nictype']);
        $this->assertSame('bond0', $eth0['parent']);
        $this->assertSame('02:54:cb:00:71:21', $eth0['hwaddr']);
        $this->assertSame('203.0.113.33', $eth0['ipv4.address']);

        $expectedYaml = <<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:cb:00:71:21"
      set-name: eth0
      addresses:
        - 203.0.113.33/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 1.1.1.1
          - 9.9.9.9
YAML;

        $this->assertSame(Yaml::parse($expectedYaml), Yaml::parse($spec['config']['cloud-init.network-config']));
    }

    public function test_manual_mode_for_vm_and_container_has_no_eth0_and_no_cloud_init_network_config(): void
    {
        foreach (['virtual-machine', 'container'] as $instanceType) {
            [$vm, $allocation] = $this->createWorkload(
                instanceType: $instanceType,
                networkMode: 'manual',
                parentInterface: '',
                cidr: '10.99.0.0/24',
                ip: '10.99.0.15',
                gateway: null,
                dnsServers: null,
            );

            $spec = $this->builder->build($vm, $allocation, 'default', ['eno1']);

            $this->assertSame($instanceType, $spec['type']);
            $this->assertArrayNotHasKey('eth0', $spec['devices']);
            $this->assertArrayHasKey('root', $spec['devices']);
            if ($instanceType === 'virtual-machine') {
                $this->assertSame([
                    'type' => 'disk',
                    'source' => 'agent:config',
                ], $spec['devices']['agent']);
            } else {
                $this->assertArrayNotHasKey('agent', $spec['devices']);
            }
            $this->assertArrayNotHasKey('cloud-init.network-config', $spec['config']);
        }
    }

    public function test_direct_netplan_generator_helpers_produce_exact_yaml(): void
    {
        $subnetWithGateway = $this->builder->generateSubnetNetplanConfig(
            macAddress: '02:54:00:00:00:10',
            ipAddress: '10.10.20.30',
            prefix: 27,
            gateway: '10.10.20.1',
            dnsServers: ['8.8.8.8'],
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:10"
      set-name: eth0
      addresses:
        - 10.10.20.30/27
      routes:
        - to: 0.0.0.0/0
          via: 10.10.20.1
      nameservers:
        addresses:
          - 8.8.8.8
YAML), Yaml::parse($subnetWithGateway));

        $subnetWithoutGateway = $this->builder->generateSubnetNetplanConfig(
            macAddress: '02:54:00:00:00:11',
            ipAddress: '10.10.20.31',
            prefix: 24,
            gateway: null,
            dnsServers: null,
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:11"
      set-name: eth0
      addresses:
        - 10.10.20.31/24
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML), Yaml::parse($subnetWithoutGateway));

        $routedYaml = $this->builder->generateNetplanConfig(
            macAddress: '02:54:00:00:00:12',
            ipAddress: '198.51.100.99',
            dnsServers: null,
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:12"
      set-name: eth0
      addresses:
        - 198.51.100.99/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML), Yaml::parse($routedYaml));

        $containerSubnetYaml = $this->builder->generateSubnetNetplanConfig(
            macAddress: '02:54:00:00:00:13',
            ipAddress: '10.10.20.32',
            prefix: 24,
            gateway: '10.10.20.1',
            dnsServers: ['1.1.1.1'],
            instanceType: 'container',
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 10.10.20.32/24
      routes:
        - to: 0.0.0.0/0
          via: 10.10.20.1
      nameservers:
        addresses:
          - 1.1.1.1
YAML), Yaml::parse($containerSubnetYaml));

        $containerRoutedYaml = $this->builder->generateNetplanConfig(
            macAddress: '02:54:00:00:00:14',
            ipAddress: '198.51.100.100',
            dnsServers: ['9.9.9.9'],
            instanceType: 'container',
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.100/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 9.9.9.9
YAML), Yaml::parse($containerRoutedYaml));
    }

    public function test_routed_netplan_routes_vary_by_os_family(): void
    {
        $ubuntuYaml = $this->builder->generateNetplanConfig(
            macAddress: '02:54:00:00:00:20',
            ipAddress: '198.51.100.110',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
            imageName: 'ubuntu/24.04',
        );

        $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:20"
      set-name: eth0
      addresses:
        - 198.51.100.110/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 1.1.1.1
YAML), Yaml::parse($ubuntuYaml));

        foreach (['debian/12', 'images:Debian/12'] as $debianImage) {
            $debianYaml = $this->builder->generateNetplanConfig(
                macAddress: '02:54:00:00:00:21',
                ipAddress: '198.51.100.111',
                dnsServers: ['1.1.1.1'],
                instanceType: 'virtual-machine',
                imageName: $debianImage,
            );

            $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:21"
      set-name: eth0
      addresses:
        - 198.51.100.111/32
      routes:
        - to: 169.254.0.1/32
        - to: 0.0.0.0/0
          via: 169.254.0.1
      nameservers:
        addresses:
          - 1.1.1.1
YAML), Yaml::parse($debianYaml));
        }

        foreach (['almalinux/9', 'rocky/9', 'rhel/9', 'centos/9-Stream', 'fedora/40'] as $rhelImage) {
            $rhelYaml = $this->builder->generateNetplanConfig(
                macAddress: '02:54:00:00:00:22',
                ipAddress: '198.51.100.112',
                dnsServers: ['1.1.1.1'],
                instanceType: 'virtual-machine',
                imageName: $rhelImage,
            );

            $this->assertSame(Yaml::parse(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      match:
        macaddress: "02:54:00:00:00:22"
      set-name: eth0
      addresses:
        - 198.51.100.112/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
      nameservers:
        addresses:
          - 1.1.1.1
YAML), Yaml::parse($rhelYaml));
        }
    }

    public function test_non_manual_modes_with_empty_parent_interface_throw_domain_exception(): void
    {
        foreach (['bridged', 'macvlan', 'routed'] as $mode) {
            [$vm, $allocation] = $this->createWorkload(
                instanceType: 'virtual-machine',
                networkMode: $mode,
                parentInterface: '',
                cidr: '10.88.0.0/24',
                ip: '10.88.0.10',
                gateway: '10.88.0.1',
                dnsServers: null,
            );

            try {
                $this->builder->build($vm, $allocation);
                $this->fail(sprintf('Expected DomainException for empty parent_interface in %s mode.', $mode));
            } catch (DomainException $e) {
                $this->assertStringContainsString('parent_interface', $e->getMessage());
                $this->assertStringContainsString($mode, $e->getMessage());
            }
        }
    }

    public function test_missing_parent_interface_on_host_throws_domain_exception_naming_interface_mode_and_available(): void
    {
        [$vm, $allocation] = $this->createWorkload(
            instanceType: 'virtual-machine',
            networkMode: 'bridged',
            parentInterface: 'br-missing',
            cidr: '10.77.0.0/24',
            ip: '10.77.0.10',
            gateway: '10.77.0.1',
            dnsServers: null,
        );

        try {
            $this->builder->build($vm, $allocation, 'default', ['eno1', 'incusbr0']);
            $this->fail('Expected DomainException when parent_interface is missing from available networks.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('br-missing', $e->getMessage());
            $this->assertStringContainsString('bridged', $e->getMessage());
            $this->assertStringContainsString('eno1, incusbr0', $e->getMessage());
        }
    }
}
