<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Image;
use App\Services\Hypervisor\GuestOs\Adapters\DebianOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\RhelOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\UbuntuOsAdapter;
use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use App\Services\Hypervisor\GuestOs\GuestOsResolver;
use Tests\TestCase;

final class GuestOsResolverTest extends TestCase
{
    private GuestOsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new GuestOsResolver;
    }

    public function test_resolves_ubuntu_adapter_from_image_and_string(): void
    {
        $image = new Image(['name' => 'ubuntu/24.04']);

        foreach ([$image, 'ubuntu/24.04', 'images:Ubuntu/24.04', '', null] as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(UbuntuOsAdapter::class, $adapter);
            $this->assertInstanceOf(DebianOsAdapter::class, $adapter);
            $this->assertSame('debian', $adapter->family());
            $this->assertSame('ubuntu', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter);
        }
    }

    public function test_resolves_debian_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'debian/12/cloud']),
            'debian/12/cloud',
            'images:Debian/12',
            new Image(['name' => 'archlinux']),
            'archlinux',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(DebianOsAdapter::class, $adapter);
            $this->assertSame('debian', $adapter->family());
            $this->assertSame('debian', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter);
        }
    }

    public function test_resolves_rhel_adapter_and_distro_usernames_from_image_and_string(): void
    {
        $cases = [
            ['almalinux/9', 'almalinux'],
            ['rockylinux/9', 'rocky'],
            ['centos/9-Stream', 'centos'],
            ['fedora/40', 'fedora'],
            ['oracle/9', 'cloud-user'],
            ['rhel/9', 'cloud-user'],
        ];

        foreach ($cases as [$imageName, $expectedUsername]) {
            foreach ([new Image(['name' => $imageName]), $imageName] as $input) {
                $adapter = $this->resolver->resolve($input);

                $this->assertInstanceOf(RhelOsAdapter::class, $adapter);
                $this->assertSame('rhel', $adapter->family());
                $this->assertSame($expectedUsername, $adapter->defaultUsername());
                $this->assertCommonAdapterDefaults($adapter);
            }
        }
    }

    public function test_ubuntu_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('ubuntu/24.04');

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:20',
            ipAddress: '198.51.100.110',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
        );

        $this->assertSame(<<<'YAML'
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
YAML, $vmYaml);

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:20',
            ipAddress: '198.51.100.110',
            dnsServers: null,
            instanceType: 'container',
        );

        $this->assertSame(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.110/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
          on-link: true
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML, $containerYaml);
    }

    public function test_debian_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('debian/12/cloud');

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:21',
            ipAddress: '198.51.100.111',
            dnsServers: ['1.1.1.1', '9.9.9.9'],
            instanceType: 'virtual-machine',
        );

        $this->assertSame(<<<'YAML'
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
          - 9.9.9.9
YAML, $vmYaml);

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:21',
            ipAddress: '198.51.100.111',
            dnsServers: ['1.1.1.1'],
            instanceType: 'container',
        );

        $this->assertSame(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.111/32
      routes:
        - to: 169.254.0.1/32
        - to: 0.0.0.0/0
          via: 169.254.0.1
      nameservers:
        addresses:
          - 1.1.1.1
YAML, $containerYaml);
    }

    public function test_rhel_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('almalinux/9');

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:22',
            ipAddress: '198.51.100.112',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
        );

        $this->assertSame(<<<'YAML'
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
YAML, $vmYaml);

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:22',
            ipAddress: '198.51.100.112',
            dnsServers: [],
            instanceType: 'container',
        );

        $this->assertSame(<<<'YAML'
network:
  version: 2
  ethernets:
    eth0:
      addresses:
        - 198.51.100.112/32
      routes:
        - to: 0.0.0.0/0
          via: 169.254.0.1
      nameservers:
        addresses:
          - 1.1.1.1
          - 8.8.8.8
YAML, $containerYaml);
    }

    private function assertCommonAdapterDefaults(GuestOsAdapterInterface $adapter): void
    {
        $this->assertSame('eth0', $adapter->primaryInterfaceName('virtual-machine'));
        $this->assertSame('eth0', $adapter->primaryInterfaceName('container'));

        $this->assertSame([
            'agent' => [
                'type' => 'disk',
                'source' => 'agent:config',
            ],
        ], $adapter->extraDevices('virtual-machine'));
        $this->assertSame([], $adapter->extraDevices('container'));

        $this->assertSame([], $adapter->extraInstanceConfig('virtual-machine'));
        $this->assertSame([], $adapter->extraInstanceConfig('container'));
        $this->assertArrayNotHasKey('migration.stateful', $adapter->extraInstanceConfig('virtual-machine'));
    }
}
