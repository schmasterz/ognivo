<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Image;
use App\Services\Hypervisor\GuestOs\Adapters\AlpineOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\ArchOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\BsdOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\DebianOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\GentooOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\RhelOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\SuseOsAdapter;
use App\Services\Hypervisor\GuestOs\Adapters\UbuntuOsAdapter;
use App\Services\Hypervisor\GuestOs\Contracts\GuestOsAdapterInterface;
use App\Services\Hypervisor\GuestOs\GuestOsResolver;
use Symfony\Component\Yaml\Yaml;
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

        foreach ([$image, 'ubuntu/24.04', 'images:Ubuntu/24.04', 'mint/22', 'pop-os/24.04', 'unknown-distro', '', null] as $input) {
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
            new Image(['name' => 'kali/rolling']),
            'kali/rolling',
            new Image(['name' => 'devuan/daedalus']),
            'devuan/daedalus',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(DebianOsAdapter::class, $adapter);
            $this->assertNotInstanceOf(UbuntuOsAdapter::class, $adapter);
            $this->assertSame('debian', $adapter->family());
            $this->assertSame('debian', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter);
        }
    }

    public function test_resolves_alpine_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'alpine/3.20']),
            'alpine/3.20',
            'images:Alpine/edge',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(AlpineOsAdapter::class, $adapter);
            $this->assertSame('alpine', $adapter->family());
            $this->assertSame('alpine', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter, expectSecureBootDisabled: true);
        }
    }

    public function test_resolves_arch_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'archlinux']),
            'archlinux',
            'archlinux/current',
            'images:arch/cloud',
            'images:Arch/current',
            new Image(['name' => 'cachyos/desktop']),
            'cachyos',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(ArchOsAdapter::class, $adapter);
            $this->assertSame('arch', $adapter->family());
            $this->assertSame('arch', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter, expectSecureBootDisabled: true);
        }
    }

    public function test_does_not_match_aarch64_as_arch_linux(): void
    {
        foreach ([new Image(['name' => 'ubuntu/24.04/aarch64']), 'ubuntu/24.04/aarch64'] as $ubuntuInput) {
            $ubuntuAdapter = $this->resolver->resolve($ubuntuInput);

            $this->assertInstanceOf(UbuntuOsAdapter::class, $ubuntuAdapter);
            $this->assertNotInstanceOf(ArchOsAdapter::class, $ubuntuAdapter);
        }

        foreach ([new Image(['name' => 'debian/12/cloud/aarch64']), 'debian/12/cloud/aarch64'] as $debianInput) {
            $debianAdapter = $this->resolver->resolve($debianInput);

            $this->assertInstanceOf(DebianOsAdapter::class, $debianAdapter);
            $this->assertNotInstanceOf(UbuntuOsAdapter::class, $debianAdapter);
            $this->assertNotInstanceOf(ArchOsAdapter::class, $debianAdapter);
        }
    }

    public function test_resolves_suse_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'opensuse/15.6']),
            'opensuse/15.6',
            'sles/15',
            'suse/tumbleweed',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(SuseOsAdapter::class, $adapter);
            $this->assertSame('suse', $adapter->family());
            $this->assertSame('opensuse', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter);
        }
    }

    public function test_resolves_gentoo_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'gentoo/current']),
            'gentoo/current',
            'images:Gentoo',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(GentooOsAdapter::class, $adapter);
            $this->assertSame('gentoo', $adapter->family());
            $this->assertSame('gentoo', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults($adapter, expectSecureBootDisabled: true);
        }
    }

    public function test_resolves_bsd_adapter_from_image_and_string(): void
    {
        $cases = [
            new Image(['name' => 'freebsd/14.1']),
            'freebsd/14.1',
            'openbsd/7.5',
            'netbsd/10.0',
        ];

        foreach ($cases as $input) {
            $adapter = $this->resolver->resolve($input);

            $this->assertInstanceOf(BsdOsAdapter::class, $adapter);
            $this->assertSame('bsd', $adapter->family());
            $this->assertSame('freebsd', $adapter->defaultUsername());
            $this->assertCommonAdapterDefaults(
                $adapter,
                expectSecureBootDisabled: true,
                vmInterfaceName: 'vtnet0',
            );
        }
    }

    public function test_resolves_rhel_adapter_and_distro_usernames_from_image_and_string(): void
    {
        $cases = [
            ['almalinux/9', 'almalinux', false],
            ['rockylinux/9', 'rocky', false],
            ['centos/9-Stream', 'centos', false],
            ['fedora/40', 'fedora', false],
            ['oracle/9', 'cloud-user', false],
            ['rhel/9', 'cloud-user', false],
            ['amazonlinux/2023', 'ec2-user', false],
            ['amazon/2', 'ec2-user', false],
            ['openeuler/24.03', 'root', true],
        ];

        foreach ($cases as [$imageName, $expectedUsername, $expectSecureBootDisabled]) {
            foreach ([new Image(['name' => $imageName]), $imageName] as $input) {
                $adapter = $this->resolver->resolve($input);

                $this->assertInstanceOf(RhelOsAdapter::class, $adapter);
                $this->assertSame('rhel', $adapter->family());
                $this->assertSame($expectedUsername, $adapter->defaultUsername());
                $this->assertCommonAdapterDefaults(
                    $adapter,
                    expectSecureBootDisabled: $expectSecureBootDisabled,
                );
            }
        }
    }

    public function test_alpine_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('alpine/3.20');

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:23',
            ipAddress: '198.51.100.113',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
        );

        $this->assertAlpineV1Config(
            yaml: $vmYaml,
            expectedAddress: '198.51.100.113/32',
            expectedGateway: '169.254.0.1',
            expectedDns: ['1.1.1.1'],
            expectedMacAddress: '02:54:00:00:00:23',
        );

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:23',
            ipAddress: '198.51.100.113',
            dnsServers: null,
            instanceType: 'container',
        );

        $this->assertAlpineV1Config(
            yaml: $containerYaml,
            expectedAddress: '198.51.100.113/32',
            expectedGateway: '169.254.0.1',
            expectedDns: ['1.1.1.1', '8.8.8.8'],
        );
    }

    public function test_ubuntu_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('ubuntu/24.04');
        $expectedRoutes = [
            [
                'to' => '0.0.0.0/0',
                'via' => '169.254.0.1',
                'on-link' => true,
            ],
        ];

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:20',
            ipAddress: '198.51.100.110',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
        );

        $this->assertNetplanV2Config(
            yaml: $vmYaml,
            expectedAddress: '198.51.100.110/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1'],
            expectedMacAddress: '02:54:00:00:00:20',
        );

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:20',
            ipAddress: '198.51.100.110',
            dnsServers: null,
            instanceType: 'container',
        );

        $this->assertNetplanV2Config(
            yaml: $containerYaml,
            expectedAddress: '198.51.100.110/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1', '8.8.8.8'],
        );
    }

    public function test_debian_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('debian/12/cloud');
        $expectedRoutes = [
            ['to' => '169.254.0.1/32'],
            ['to' => '0.0.0.0/0', 'via' => '169.254.0.1'],
        ];

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:21',
            ipAddress: '198.51.100.111',
            dnsServers: ['1.1.1.1', '9.9.9.9'],
            instanceType: 'virtual-machine',
        );

        $this->assertNetplanV2Config(
            yaml: $vmYaml,
            expectedAddress: '198.51.100.111/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1', '9.9.9.9'],
            expectedMacAddress: '02:54:00:00:00:21',
        );

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:21',
            ipAddress: '198.51.100.111',
            dnsServers: ['1.1.1.1'],
            instanceType: 'container',
        );

        $this->assertNetplanV2Config(
            yaml: $containerYaml,
            expectedAddress: '198.51.100.111/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1'],
        );
    }

    public function test_rhel_routed_network_config_for_vm_and_container(): void
    {
        $adapter = $this->resolver->resolve('almalinux/9');
        $expectedRoutes = [
            ['to' => '0.0.0.0/0', 'via' => '169.254.0.1'],
        ];

        $vmYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:22',
            ipAddress: '198.51.100.112',
            dnsServers: ['1.1.1.1'],
            instanceType: 'virtual-machine',
        );

        $this->assertNetplanV2Config(
            yaml: $vmYaml,
            expectedAddress: '198.51.100.112/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1'],
            expectedMacAddress: '02:54:00:00:00:22',
        );

        $containerYaml = $adapter->buildRoutedNetworkConfig(
            macAddress: '02:54:00:00:00:22',
            ipAddress: '198.51.100.112',
            dnsServers: [],
            instanceType: 'container',
        );

        $this->assertNetplanV2Config(
            yaml: $containerYaml,
            expectedAddress: '198.51.100.112/32',
            expectedRoutes: $expectedRoutes,
            expectedDns: ['1.1.1.1', '8.8.8.8'],
        );
    }

    /**
     * Assert semantic structure of a Cloud-Init Network Config v1 (Alpine) YAML payload.
     *
     * @param  array<int, string>  $expectedDns
     */
    private function assertAlpineV1Config(
        ?string $yaml,
        string $expectedAddress,
        string $expectedGateway,
        array $expectedDns,
        ?string $expectedMacAddress = null,
    ): void {
        $this->assertNotNull($yaml);
        $this->assertStringContainsString('version: 1', $yaml);
        $this->assertStringContainsString($expectedGateway, $yaml);

        $parsed = Yaml::parse($yaml);
        $this->assertIsArray($parsed);
        $this->assertSame(1, $parsed['version'] ?? null);
        $this->assertCount(1, $parsed['config'] ?? []);

        $interface = $parsed['config'][0];
        $this->assertIsArray($interface);
        $this->assertSame('physical', $interface['type'] ?? null);
        $this->assertSame('eth0', $interface['name'] ?? null);

        if ($expectedMacAddress !== null) {
            $this->assertSame($expectedMacAddress, $interface['mac_address'] ?? null);
        } else {
            $this->assertArrayNotHasKey('mac_address', $interface);
        }

        $this->assertSame([
            [
                'type' => 'static',
                'address' => $expectedAddress,
                'gateway' => $expectedGateway,
                'dns_nameservers' => $expectedDns,
            ],
        ], $interface['subnets'] ?? null);
    }

    /**
     * Assert semantic structure of a Netplan v2 YAML payload.
     *
     * @param  array<int, array<string, mixed>>  $expectedRoutes
     * @param  array<int, string>  $expectedDns
     */
    private function assertNetplanV2Config(
        ?string $yaml,
        string $expectedAddress,
        array $expectedRoutes,
        array $expectedDns,
        ?string $expectedMacAddress = null,
    ): void {
        $this->assertNotNull($yaml);
        $this->assertStringContainsString('version: 2', $yaml);

        $parsed = Yaml::parse($yaml);
        $this->assertIsArray($parsed);
        $this->assertSame(2, $parsed['network']['version'] ?? null);

        $eth0 = $parsed['network']['ethernets']['eth0'] ?? null;
        $this->assertIsArray($eth0);

        if ($expectedMacAddress !== null) {
            $this->assertSame(['macaddress' => $expectedMacAddress], $eth0['match'] ?? null);
            $this->assertSame('eth0', $eth0['set-name'] ?? null);
        } else {
            $this->assertArrayNotHasKey('match', $eth0);
            $this->assertArrayNotHasKey('set-name', $eth0);
        }

        $this->assertSame([$expectedAddress], $eth0['addresses'] ?? null);
        $this->assertSame($expectedRoutes, $eth0['routes'] ?? null);
        $this->assertSame(['addresses' => $expectedDns], $eth0['nameservers'] ?? null);
    }

    private function assertCommonAdapterDefaults(
        GuestOsAdapterInterface $adapter,
        bool $expectSecureBootDisabled = false,
        string $vmInterfaceName = 'eth0',
    ): void {
        $this->assertSame($vmInterfaceName, $adapter->primaryInterfaceName('virtual-machine'));
        $this->assertSame('eth0', $adapter->primaryInterfaceName('container'));

        $this->assertSame([
            'agent' => [
                'type' => 'disk',
                'source' => 'agent:config',
            ],
        ], $adapter->extraDevices('virtual-machine'));
        $this->assertSame([], $adapter->extraDevices('container'));

        $expectedVmConfig = $expectSecureBootDisabled ? ['security.secureboot' => 'false'] : [];
        $this->assertSame($expectedVmConfig, $adapter->extraInstanceConfig('virtual-machine'));
        $this->assertSame([], $adapter->extraInstanceConfig('container'));
        $this->assertArrayNotHasKey('migration.stateful', $adapter->extraInstanceConfig('virtual-machine'));
    }
}
