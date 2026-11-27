<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Hypervisor\NicDeviceBuilder;
use InvalidArgumentException;
use Tests\TestCase;

final class NicDeviceBuilderTest extends TestCase
{
    private NicDeviceBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new NicDeviceBuilder;
    }

    public function test_limit_keys_constant_matches_incus_ingress_and_egress_keys(): void
    {
        $this->assertSame([
            'download' => 'limits.ingress',
            'upload' => 'limits.egress',
        ], NicDeviceBuilder::LIMIT_KEYS);
    }

    public function test_builds_routed_nic_device_with_and_without_bandwidth_limits(): void
    {
        $withoutLimits = $this->builder->build(
            networkMode: 'routed',
            parentInterface: 'bond0',
            ipAddress: '203.0.113.25',
            macAddress: '02:54:cb:00:71:19',
        );

        $this->assertSame([
            'type' => 'nic',
            'nictype' => 'routed',
            'parent' => 'bond0',
            'ipv4.address' => '203.0.113.25',
            'hwaddr' => '02:54:cb:00:71:19',
        ], $withoutLimits);

        $withLimits = $this->builder->build(
            networkMode: 'routed',
            parentInterface: 'bond0',
            ipAddress: '203.0.113.25',
            macAddress: '02:54:cb:00:71:19',
            downloadMbps: 1000,
            uploadMbps: 500,
        );

        $this->assertSame([
            'type' => 'nic',
            'nictype' => 'routed',
            'parent' => 'bond0',
            'ipv4.address' => '203.0.113.25',
            'hwaddr' => '02:54:cb:00:71:19',
            'limits.ingress' => '1000Mbit',
            'limits.egress' => '500Mbit',
        ], $withLimits);
    }

    public function test_builds_routed_nic_device_omitting_empty_ip_and_mac(): void
    {
        $nic = $this->builder->build(
            networkMode: 'routed',
            parentInterface: 'eth0',
            ipAddress: '',
            macAddress: null,
        );

        $this->assertSame([
            'type' => 'nic',
            'nictype' => 'routed',
            'parent' => 'eth0',
        ], $nic);
    }

    public function test_builds_bridged_and_macvlan_nic_devices_without_ipv4_address_and_with_optional_limits(): void
    {
        foreach (['bridged', 'macvlan'] as $mode) {
            $withoutLimits = $this->builder->build(
                networkMode: $mode,
                parentInterface: 'incusbr0',
                ipAddress: '10.241.79.50',
                macAddress: '02:54:0a:f1:4f:32',
            );

            $this->assertSame([
                'type' => 'nic',
                'nictype' => $mode,
                'parent' => 'incusbr0',
                'hwaddr' => '02:54:0a:f1:4f:32',
            ], $withoutLimits);

            $downloadOnly = $this->builder->build(
                networkMode: $mode,
                parentInterface: 'incusbr0',
                ipAddress: '10.241.79.50',
                macAddress: null,
                downloadMbps: 250,
            );

            $this->assertSame([
                'type' => 'nic',
                'nictype' => $mode,
                'parent' => 'incusbr0',
                'limits.ingress' => '250Mbit',
            ], $downloadOnly);

            $uploadOnly = $this->builder->build(
                networkMode: $mode,
                parentInterface: 'incusbr0',
                ipAddress: null,
                macAddress: '',
                uploadMbps: 100,
            );

            $this->assertSame([
                'type' => 'nic',
                'nictype' => $mode,
                'parent' => 'incusbr0',
                'limits.egress' => '100Mbit',
            ], $uploadOnly);
        }
    }

    public function test_throws_on_unsupported_network_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->builder->build(
            networkMode: 'manual',
            parentInterface: 'eth0',
        );
    }
}
