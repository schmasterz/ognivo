<?php

declare(strict_types=1);

use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\Services\Hypervisor\ResourceCapabilityMatrix;

it('evaluates all container resource upgrades as Online on quota-supporting storage drivers and confirmed nictypes', function (
    string $vmState,
    string $storageDriver,
    string $nicType,
): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(
        cpuCores: 1,
        ramMb: 512,
        diskGb: 10,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 100, uploadMbps: 50),
    );
    $target = new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 250, uploadMbps: 100),
    );

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: $vmState,
        storageDriver: $storageDriver,
        nicType: $nicType,
    );

    expect($assessment->isApplicable())->toBeTrue()
        ->and($assessment->requiresStop())->toBeFalse()
        ->and($assessment->reasons)->toBe([])
        ->and($assessment->verdicts)->toBe([
            ResourceKind::Cpu->value => ResourceUpdateVerdict::Online,
            ResourceKind::Ram->value => ResourceUpdateVerdict::Online,
            ResourceKind::Disk->value => ResourceUpdateVerdict::Online,
            ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
            ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
        ]);
})->with([
    'running zfs routed' => ['Running', 'zfs', 'routed'],
    'running btrfs bridged' => ['Running', 'btrfs', 'bridged'],
    'running lvm macvlan' => ['Running', 'lvm', 'macvlan'],
    'stopped lvmcluster routed' => ['Stopped', 'lvmcluster', 'routed'],
    'stopped ceph bridged' => ['Stopped', 'ceph', 'bridged'],
]);

it('marks container disk grow as Unsupported on dir or unknown storage driver', function (string $vmState, ?string $storageDriver): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(2, 1024, 20, 'container');

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: $vmState,
        storageDriver: $storageDriver,
        nicType: 'routed',
    );

    expect($assessment->isApplicable())->toBeFalse()
        ->and($assessment->verdicts[ResourceKind::Cpu->value])->toBe(ResourceUpdateVerdict::Online)
        ->and($assessment->verdicts[ResourceKind::Ram->value])->toBe(ResourceUpdateVerdict::Online)
        ->and($assessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->reasons[ResourceKind::Disk->value] ?? null)->toBe('STORAGE_QUOTA_UNSUPPORTED');
})->with([
    'running dir' => ['Running', 'dir'],
    'stopped dir' => ['Stopped', 'dir'],
    'running null storage' => ['Running', null],
    'running empty storage' => ['Running', ''],
]);

it('allows CPU and RAM container upgrades on dir storage when disk size is unchanged', function (): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(2, 1024, 10, 'container');

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: 'Running',
        storageDriver: 'dir',
        nicType: 'routed',
    );

    expect($assessment->isApplicable())->toBeTrue()
        ->and($assessment->requiresStop())->toBeFalse()
        ->and($assessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::Unchanged)
        ->and($assessment->verdicts[ResourceKind::Cpu->value])->toBe(ResourceUpdateVerdict::Online)
        ->and($assessment->verdicts[ResourceKind::Ram->value])->toBe(ResourceUpdateVerdict::Online);
});

it('marks network bandwidth updates as Unsupported for unconfirmed NIC types', function (string $instanceType, string $nicType): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(2, 2048, 20, $instanceType, new NetworkLimitsSpec(100, 100));
    $target = new InstanceResourceSpec(2, 2048, 20, $instanceType, new NetworkLimitsSpec(200, 200));

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: 'Running',
        storageDriver: 'zfs',
        nicType: $nicType,
    );

    expect($assessment->isApplicable())->toBeFalse()
        ->and($assessment->verdicts[ResourceKind::NetDownload->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->verdicts[ResourceKind::NetUpload->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->reasons[ResourceKind::NetDownload->value] ?? null)->toBe('NIC_TYPE_UNSUPPORTED')
        ->and($assessment->reasons[ResourceKind::NetUpload->value] ?? null)->toBe('NIC_TYPE_UNSUPPORTED');
})->with([
    'container ovn' => ['container', 'ovn'],
    'container p2p' => ['container', 'p2p'],
    'vm sriov' => ['virtual-machine', 'sriov'],
    'vm empty nictype' => ['virtual-machine', ''],
]);

it('requires stop for running virtual machine CPU, RAM, and disk changes while keeping confirmed NIC changes Online', function (string $nicType): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'virtual-machine',
        network: new NetworkLimitsSpec(100, 50),
    );
    $target = new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'virtual-machine',
        network: new NetworkLimitsSpec(500, 250),
    );

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: 'Running',
        storageDriver: 'dir',
        nicType: $nicType,
    );

    expect($assessment->isApplicable())->toBeTrue()
        ->and($assessment->requiresStop())->toBeTrue()
        ->and($assessment->verdicts)->toBe([
            ResourceKind::Cpu->value => ResourceUpdateVerdict::RequiresStop,
            ResourceKind::Ram->value => ResourceUpdateVerdict::RequiresStop,
            ResourceKind::Disk->value => ResourceUpdateVerdict::RequiresStop,
            ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
            ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
        ]);

    // Network-only change on a running VM is Online without requiring stop
    $networkOnly = $matrix->assess(
        observed: $observed,
        target: new InstanceResourceSpec(2, 2048, 20, 'virtual-machine', new NetworkLimitsSpec(500, 250)),
        vmState: 'Running',
        storageDriver: 'dir',
        nicType: $nicType,
    );

    expect($networkOnly->isApplicable())->toBeTrue()
        ->and($networkOnly->requiresStop())->toBeFalse()
        ->and($networkOnly->verdicts[ResourceKind::NetDownload->value])->toBe(ResourceUpdateVerdict::Online)
        ->and($networkOnly->verdicts[ResourceKind::NetUpload->value])->toBe(ResourceUpdateVerdict::Online);
})->with(['routed', 'bridged', 'macvlan']);

it('evaluates stopped virtual machine upgrades as Online', function (): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(2, 2048, 20, 'virtual-machine', new NetworkLimitsSpec(100, 100));
    $target = new InstanceResourceSpec(4, 4096, 40, 'virtual-machine', new NetworkLimitsSpec(500, 500));

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: 'Stopped',
        storageDriver: null,
        nicType: 'routed',
    );

    expect($assessment->isApplicable())->toBeTrue()
        ->and($assessment->requiresStop())->toBeFalse()
        ->and($assessment->verdicts)->toBe([
            ResourceKind::Cpu->value => ResourceUpdateVerdict::Online,
            ResourceKind::Ram->value => ResourceUpdateVerdict::Online,
            ResourceKind::Disk->value => ResourceUpdateVerdict::Online,
            ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
            ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
        ]);
});

it('marks disk shrink as Unsupported with DISK_SHRINK regardless of instance type or state', function (
    string $instanceType,
    string $vmState,
): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(2, 2048, 40, $instanceType);
    $target = new InstanceResourceSpec(2, 2048, 20, $instanceType);

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: $vmState,
        storageDriver: 'zfs',
        nicType: 'routed',
    );

    expect($assessment->isApplicable())->toBeFalse()
        ->and($assessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->reasons[ResourceKind::Disk->value] ?? null)->toBe('DISK_SHRINK');
})->with([
    'running container' => ['container', 'Running'],
    'stopped container' => ['container', 'Stopped'],
    'running vm' => ['virtual-machine', 'Running'],
    'stopped vm' => ['virtual-machine', 'Stopped'],
]);

it('marks network updates as Unsupported with NETWORK_LIMIT_NO_NIC when no local NIC exists', function (): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(1, 512, 10, 'virtual-machine', null);
    $targetWithNet = new InstanceResourceSpec(1, 512, 10, 'virtual-machine', new NetworkLimitsSpec(100, 50));

    $assessment = $matrix->assess(
        observed: $observed,
        target: $targetWithNet,
        vmState: 'Stopped',
        storageDriver: 'zfs',
        nicType: null,
    );

    expect($assessment->isApplicable())->toBeFalse()
        ->and($assessment->verdicts[ResourceKind::NetDownload->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->verdicts[ResourceKind::NetUpload->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($assessment->reasons[ResourceKind::NetDownload->value] ?? null)->toBe('NETWORK_LIMIT_NO_NIC')
        ->and($assessment->reasons[ResourceKind::NetUpload->value] ?? null)->toBe('NETWORK_LIMIT_NO_NIC');

    // Unchanged network limits without local NIC remain applicable
    $unchangedNet = $matrix->assess(
        observed: $observed,
        target: new InstanceResourceSpec(2, 1024, 10, 'virtual-machine', null),
        vmState: 'Stopped',
        storageDriver: 'zfs',
        nicType: null,
    );

    expect($unchangedNet->isApplicable())->toBeTrue()
        ->and($unchangedNet->verdicts[ResourceKind::NetDownload->value])->toBe(ResourceUpdateVerdict::Unchanged)
        ->and($unchangedNet->verdicts[ResourceKind::NetUpload->value])->toBe(ResourceUpdateVerdict::Unchanged);
});

it('falls back conservatively to RequiresStop for non-standard VM states', function (): void {
    $matrix = new ResourceCapabilityMatrix;

    $observed = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(2, 1024, 10, 'container');

    $assessment = $matrix->assess(
        observed: $observed,
        target: $target,
        vmState: 'Frozen',
        storageDriver: 'zfs',
        nicType: 'routed',
    );

    expect($assessment->isApplicable())->toBeTrue()
        ->and($assessment->requiresStop())->toBeTrue()
        ->and($assessment->verdicts[ResourceKind::Cpu->value])->toBe(ResourceUpdateVerdict::RequiresStop)
        ->and($assessment->verdicts[ResourceKind::Ram->value])->toBe(ResourceUpdateVerdict::RequiresStop);
});
