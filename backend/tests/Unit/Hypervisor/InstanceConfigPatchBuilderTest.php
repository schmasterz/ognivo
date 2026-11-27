<?php

declare(strict_types=1);

use App\Services\Hypervisor\InstanceConfigPatchBuilder;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use App\ValueObjects\Hypervisor\NetworkLimitsSpec;

it('parses binary and decimal sizes accurately via parseSizeToBytes', function (string $input, int $expectedBytes): void {
    $builder = new InstanceConfigPatchBuilder;

    expect($builder->parseSizeToBytes($input))->toBe($expectedBytes);
})->with([
    'bytes' => ['512B', 512],
    'bare integer' => ['1048576', 1048576],
    'decimal KB' => ['10KB', 10_000],
    'decimal MB' => ['512MB', 512_000_000],
    'decimal GB' => ['20GB', 20_000_000_000],
    'decimal TB' => ['1TB', 1_000_000_000_000],
    'binary KiB' => ['4KiB', 4096],
    'binary MiB' => ['512MiB', 512 * 1024 * 1024],
    'binary GiB' => ['20GiB', 20 * 1024 * 1024 * 1024],
    'binary TiB' => ['1TiB', 1024 ** 4],
    'fractional GiB with spaces' => [' 1.5 GiB ', (int) round(1.5 * (1024 ** 3))],
]);

it('rejects empty or invalid size strings in parseSizeToBytes', function (string $invalid): void {
    $builder = new InstanceConfigPatchBuilder;

    expect(fn (): int => $builder->parseSizeToBytes($invalid))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'whitespace' => ['   '],
    'non-numeric' => ['abcGiB'],
    'unsupported unit' => ['100PB'],
]);

it('parses binary and decimal specs accurately via parseSpec', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $binarySpec = $builder->parseSpec([
        'type' => 'container',
        'expanded_config' => [
            'limits.cpu' => '2',
            'limits.memory' => '1GiB',
        ],
        'config' => [
            'limits.memory' => '2048MiB',
        ],
        'devices' => [
            'root' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'zfs-pool',
                'size' => '20GiB',
            ],
            'eth0' => [
                'type' => 'nic',
                'nictype' => 'routed',
                'limits.ingress' => '100Mbit',
                'limits.egress' => '1Gbit',
            ],
        ],
    ]);

    expect($binarySpec)->toEqual(new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 100, uploadMbps: 1000),
    ));

    $decimalSpec = $builder->parseSpec([
        'type' => 'virtual-machine',
        'config' => [
            'limits.cpu' => '4',
            'limits.memory' => '2000MB',
        ],
        'devices' => [
            'rootfs' => [
                'type' => 'disk',
                'path' => '/',
                'size' => '20GB',
            ],
            'enp5s0' => [
                'type' => 'nic',
                'nictype' => 'bridged',
                'limits.ingress' => '50000Kbit',
            ],
        ],
    ]);

    expect($decimalSpec)->toEqual(new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: intdiv(2_000_000_000, 1024 * 1024),
        diskGb: intdiv(20_000_000_000, 1024 * 1024 * 1024),
        instanceType: 'virtual-machine',
        network: new NetworkLimitsSpec(downloadMbps: 50, uploadMbps: null),
    ));
});

it('preserves unrelated config and device keys while formatting target resources', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $current = [
        'name' => 'vps-101',
        'type' => 'container',
        'status' => 'Running',
        'config' => [
            'cloud-init.user-data' => "#cloud-config\nhostname: vps-101\n",
            'cloud-init.network-config' => 'version: 2',
            'image.os' => 'Ubuntu',
            'image.release' => '24.04',
            'security.nesting' => 'false',
            'limits.cpu' => '1',
            'limits.memory' => '512MiB',
        ],
        'devices' => [
            'root' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'tank',
                'size' => '10GiB',
            ],
            'data' => [
                'type' => 'disk',
                'path' => '/mnt/data',
                'pool' => 'tank',
                'source' => 'vol-data-1',
            ],
            'eth0' => [
                'type' => 'nic',
                'nictype' => 'routed',
                'parent' => 'eth0',
                'ipv4.address' => '203.0.113.10',
                'hwaddr' => '02:54:cb:00:71:0a',
            ],
        ],
    ];

    $target = new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 250, uploadMbps: 100),
    );

    $payload = $builder->buildPutPayload($current, $target);

    expect($payload['name'])->toBe('vps-101')
        ->and($payload['config'])->toBe([
            'cloud-init.user-data' => "#cloud-config\nhostname: vps-101\n",
            'cloud-init.network-config' => 'version: 2',
            'image.os' => 'Ubuntu',
            'image.release' => '24.04',
            'security.nesting' => 'false',
            'limits.cpu' => '4',
            'limits.memory' => '4096MiB',
        ])
        ->and($payload['devices']['root'])->toBe([
            'type' => 'disk',
            'path' => '/',
            'pool' => 'tank',
            'size' => '40GiB',
        ])
        ->and($payload['devices']['data'])->toBe([
            'type' => 'disk',
            'path' => '/mnt/data',
            'pool' => 'tank',
            'source' => 'vol-data-1',
        ])
        ->and($payload['devices']['eth0'])->toBe([
            'type' => 'nic',
            'nictype' => 'routed',
            'parent' => 'eth0',
            'ipv4.address' => '203.0.113.10',
            'hwaddr' => '02:54:cb:00:71:0a',
            'limits.ingress' => '250Mbit',
            'limits.egress' => '100Mbit',
        ]);
});

it('removes limits.ingress and limits.egress when target network limit is null', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $current = [
        'type' => 'container',
        'config' => [
            'limits.cpu' => '2',
            'limits.memory' => '1024MiB',
        ],
        'devices' => [
            'root' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'default',
                'size' => '20GiB',
            ],
            'eth0' => [
                'type' => 'nic',
                'nictype' => 'bridged',
                'parent' => 'br0',
                'hwaddr' => '02:00:00:00:00:01',
                'limits.ingress' => '100Mbit',
                'limits.egress' => '50Mbit',
            ],
        ],
    ];

    // Partial removal: keep download, remove upload
    $partialPayload = $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 300, uploadMbps: null),
    ));

    expect($partialPayload['devices']['eth0'])
        ->toHaveKey('limits.ingress', '300Mbit')
        ->not->toHaveKey('limits.egress')
        ->toHaveKey('hwaddr', '02:00:00:00:00:01');

    // Full removal via null network spec
    $unlimitedPayload = $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 20,
        instanceType: 'container',
        network: null,
    ));

    expect($unlimitedPayload['devices']['eth0'])
        ->not->toHaveKey('limits.ingress')
        ->not->toHaveKey('limits.egress')
        ->toHaveKey('parent', 'br0');
});

it('dynamically locates non-standard root disk and local nic device names', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $current = [
        'type' => 'virtual-machine',
        'config' => [
            'limits.cpu' => '2',
            'limits.memory' => '2048MiB',
        ],
        'devices' => [
            'scratch' => [
                'type' => 'disk',
                'path' => '/var/lib/scratch',
                'pool' => 'fast',
                'size' => '100GiB',
            ],
            'rootfs' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'fast',
                'size' => '20GiB',
            ],
            'enp5s0' => [
                'type' => 'nic',
                'nictype' => 'macvlan',
                'parent' => 'eth0',
            ],
        ],
    ];

    expect($builder->findRootDiskDevice($current)['name'] ?? null)->toBe('rootfs')
        ->and($builder->findLocalNicDevice($current)['name'] ?? null)->toBe('enp5s0');

    $payload = $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 30,
        instanceType: 'virtual-machine',
        network: new NetworkLimitsSpec(downloadMbps: 500, uploadMbps: 500),
    ));

    expect($payload['devices']['rootfs']['size'])->toBe('30GiB')
        ->and($payload['devices']['scratch']['size'])->toBe('100GiB')
        ->and($payload['devices']['enp5s0']['limits.ingress'])->toBe('500Mbit')
        ->and($payload['devices']['enp5s0']['limits.egress'])->toBe('500Mbit');
});

it('materializes root disk from expanded_devices when absent from local devices', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $current = [
        'type' => 'container',
        'config' => [],
        'devices' => [
            'eth0' => [
                'type' => 'nic',
                'nictype' => 'bridged',
                'parent' => 'incusbr0',
            ],
        ],
        'expanded_devices' => [
            'os-disk' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'default',
                'size' => '10GiB',
            ],
        ],
    ];

    $payload = $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 25,
        instanceType: 'container',
    ));

    expect($payload['devices']['os-disk'])->toBe([
        'type' => 'disk',
        'path' => '/',
        'pool' => 'default',
        'size' => '25GiB',
    ]);
});

it('throws NETWORK_LIMIT_NO_NIC when limits are requested without a local nic device', function (): void {
    $builder = new InstanceConfigPatchBuilder;

    $current = [
        'type' => 'container',
        'config' => [
            'limits.cpu' => '1',
            'limits.memory' => '512MiB',
        ],
        'devices' => [
            'root' => [
                'type' => 'disk',
                'path' => '/',
                'pool' => 'default',
                'size' => '10GiB',
            ],
        ],
        // Profile-inherited NIC in expanded_devices does not count as a local NIC
        'expanded_devices' => [
            'eth0' => [
                'type' => 'nic',
                'nictype' => 'bridged',
                'parent' => 'incusbr0',
            ],
        ],
    ];

    expect(fn (): array => $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 10,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 100, uploadMbps: null),
    )))->toThrow(DomainException::class, 'NETWORK_LIMIT_NO_NIC');

    // Should succeed when no network limits are requested
    $payload = $builder->buildPutPayload($current, new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 10,
        instanceType: 'container',
        network: null,
    ));

    expect($payload['config']['limits.cpu'])->toBe('2');
});
