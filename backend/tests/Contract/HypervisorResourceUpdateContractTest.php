<?php

declare(strict_types=1);

use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use App\DTO\Hypervisor\OperationState;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateStatus;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\Exceptions\DriverUnavailableException;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Contracts\HypervisorDriverInterface;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

dataset('resourceUpdateDrivers', [
    'fake' => ['fake'],
    'incus' => ['incus'],
]);

/**
 * Build an Incus instance metadata payload matching the given spec and capabilities.
 *
 * @return array<string, mixed>
 */
function buildContractIncusInstance(
    string $name,
    InstanceResourceSpec $spec,
    string $status = 'Running',
    string $storagePool = 'zfs-pool',
    ?string $nicType = 'routed',
): array {
    $devices = [
        'root' => [
            'type' => 'disk',
            'path' => '/',
            'pool' => $storagePool,
            'size' => sprintf('%dGiB', $spec->diskGb),
        ],
    ];

    if ($nicType !== null) {
        $nic = [
            'type' => 'nic',
            'nictype' => $nicType,
            'parent' => 'eth0',
            'ipv4.address' => '203.0.113.10',
        ];
        if ($spec->network?->downloadMbps !== null) {
            $nic['limits.ingress'] = sprintf('%dMbit', $spec->network->downloadMbps);
        }
        if ($spec->network?->uploadMbps !== null) {
            $nic['limits.egress'] = sprintf('%dMbit', $spec->network->uploadMbps);
        }
        $devices['eth0'] = $nic;
    }

    return [
        'name' => $name,
        'type' => $spec->instanceType,
        'status' => $status,
        'config' => [
            'limits.cpu' => (string) $spec->cpuCores,
            'limits.memory' => sprintf('%dMiB', $spec->ramMb),
            'image.os' => 'Ubuntu',
        ],
        'devices' => $devices,
    ];
}

function makeContractDriver(string $driverType, Host $host): HypervisorDriverInterface
{
    return $driverType === 'fake'
        ? new FakeHypervisorDriver
        : new IncusDriver($host);
}

beforeEach(function (): void {
    app(PanelCertificateService::class)->generate(365);

    $this->user = User::factory()->create([
        'project_name' => 'tenant-contract-proj',
    ]);

    $this->host = Host::create([
        'name' => 'incus-contract-node',
        'endpoint_url' => 'https://incus-contract.local:8443',
        'driver' => 'incus',
        'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('c', 32)),
        'is_active' => true,
    ]);

    $this->vm = VirtualMachine::create([
        'user_id' => $this->user->id,
        'host_id' => $this->host->id,
        'name' => 'ct-contract-01',
        'incus_project' => 'tenant-contract-proj',
        'instance_type' => 'container',
        'status' => 'running',
        'cpu_cores' => 1,
        'ram_mb' => 512,
        'disk_gb' => 10,
        'os_image' => 'images:ubuntu/24.04',
    ]);
});

afterEach(function (): void {
    HypervisorManager::resetFake();
});

it('retrieves current observed resource specification via getInstanceResourceSpec', function (string $driverType): void {
    $expected = new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 25,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 200, uploadMbps: 100),
    );

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $expected);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => buildContractIncusInstance($this->vm->name, $expected),
            ], 200, ['ETag' => '"etag-1"']),
        ]);
    }

    $observed = $driver->getInstanceResourceSpec($this->vm);

    expect($observed)->toEqual($expected);
})->with('resourceUpdateDrivers');

it('throws DriverUnavailableException on getInstanceResourceSpec and assessResourceUpdate when host is unreachable', function (string $driverType): void {
    $target = new InstanceResourceSpec(2, 1024, 10, 'container');
    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setHostUnreachable($this->vm);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/*' => Http::response(['error' => 'Service unavailable'], 503),
        ]);
    }

    expect(fn (): InstanceResourceSpec => $driver->getInstanceResourceSpec($this->vm))
        ->toThrow(DriverUnavailableException::class)
        ->and(fn () => $driver->assessResourceUpdate($this->vm, $target))
        ->toThrow(DriverUnavailableException::class);
})->with('resourceUpdateDrivers');

it('assesses online, requires-stop, and unsupported resource updates consistently via assessResourceUpdate', function (string $driverType): void {
    $observedContainer = new InstanceResourceSpec(
        cpuCores: 1,
        ramMb: 512,
        diskGb: 10,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 100, uploadMbps: 50),
    );
    $upgradeContainer = new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 1024,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(downloadMbps: 250, uploadMbps: 100),
    );

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $observedContainer)
            ->setInstanceStatus($this->vm, 'Running')
            ->setInstanceStorageDriver($this->vm, 'zfs')
            ->setInstanceNicType($this->vm, 'routed');
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::response([
                'type' => 'sync',
                'metadata' => buildContractIncusInstance($this->vm->name, $observedContainer, 'Running', 'zfs-pool', 'routed'),
            ], 200),
            'https://incus-contract.local:8443/1.0/storage-pools/zfs-pool' => Http::response([
                'type' => 'sync',
                'metadata' => ['name' => 'zfs-pool', 'driver' => 'zfs'],
            ], 200),
        ]);
    }

    $onlineAssessment = $driver->assessResourceUpdate($this->vm, $upgradeContainer);
    expect($onlineAssessment->isApplicable())->toBeTrue()
        ->and($onlineAssessment->requiresStop())->toBeFalse()
        ->and($onlineAssessment->verdicts[ResourceKind::Cpu->value])->toBe(ResourceUpdateVerdict::Online)
        ->and($onlineAssessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::Online);

    // Disk shrink must be flagged Unsupported (DISK_SHRINK)
    $shrinkAssessment = $driver->assessResourceUpdate($this->vm, new InstanceResourceSpec(1, 512, 5, 'container'));
    expect($shrinkAssessment->isApplicable())->toBeFalse()
        ->and($shrinkAssessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::Unsupported)
        ->and($shrinkAssessment->reasons[ResourceKind::Disk->value] ?? null)->toBe('DISK_SHRINK');

    // Running VM must report RequiresStop for compute/disk and Online for routed NIC
    $runningVm = VirtualMachine::create([
        'user_id' => $this->user->id,
        'host_id' => $this->host->id,
        'name' => 'vm-contract-01',
        'incus_project' => 'tenant-contract-proj',
        'instance_type' => 'virtual-machine',
        'status' => 'running',
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'os_image' => 'images:ubuntu/24.04',
    ]);

    $observedVm = new InstanceResourceSpec(2, 2048, 20, 'virtual-machine', new NetworkLimitsSpec(100, 100));
    $targetVm = new InstanceResourceSpec(4, 4096, 40, 'virtual-machine', new NetworkLimitsSpec(500, 500));

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($runningVm, $observedVm)
            ->setInstanceStatus($runningVm, 'Running')
            ->setInstanceNicType($runningVm, 'routed');
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/vm-contract-01?project=tenant-contract-proj' => Http::response([
                'type' => 'sync',
                'metadata' => buildContractIncusInstance($runningVm->name, $observedVm, 'Running', 'dir-pool', 'routed'),
            ], 200),
        ]);
    }

    $vmAssessment = $driver->assessResourceUpdate($runningVm, $targetVm);
    expect($vmAssessment->isApplicable())->toBeTrue()
        ->and($vmAssessment->requiresStop())->toBeTrue()
        ->and($vmAssessment->verdicts[ResourceKind::Cpu->value])->toBe(ResourceUpdateVerdict::RequiresStop)
        ->and($vmAssessment->verdicts[ResourceKind::Ram->value])->toBe(ResourceUpdateVerdict::RequiresStop)
        ->and($vmAssessment->verdicts[ResourceKind::Disk->value])->toBe(ResourceUpdateVerdict::RequiresStop)
        ->and($vmAssessment->verdicts[ResourceKind::NetDownload->value])->toBe(ResourceUpdateVerdict::Online);
})->with('resourceUpdateDrivers');

it('applies resource update synchronously and persists updated spec via applyResourceUpdate', function (string $driverType): void {
    $initial = new InstanceResourceSpec(1, 512, 10, 'container', new NetworkLimitsSpec(100, 50));
    $target = new InstanceResourceSpec(4, 2048, 30, 'container', new NetworkLimitsSpec(500, 250));

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $initial);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::sequence()
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $initial),
                ], 200, ['ETag' => '"etag-initial"'])
                ->push([
                    'type' => 'async',
                    'status' => 'Operation created',
                    'status_code' => 100,
                    'operation' => '/1.0/operations/op-resize-sync-1',
                ], 202)
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $target),
                ], 200, ['ETag' => '"etag-updated"']),
            'https://incus-contract.local:8443/1.0/operations/op-resize-sync-1/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => ['status' => 'Success', 'status_code' => 200],
            ], 200),
        ]);
    }

    $result = $driver->applyResourceUpdate($this->vm, $target);

    expect($result->status)->toBe(ResourceUpdateStatus::Applied)
        ->and($driver->getInstanceResourceSpec($this->vm))->toEqual($target);

    if ($driver instanceof IncusDriver) {
        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $request->hasHeader('If-Match', '"etag-initial"')
                && ($data['config']['limits.cpu'] ?? null) === '4'
                && ($data['config']['limits.memory'] ?? null) === '2048MiB'
                && ($data['devices']['root']['size'] ?? null) === '30GiB'
                && ($data['devices']['eth0']['limits.ingress'] ?? null) === '500Mbit'
                && ($data['devices']['eth0']['limits.egress'] ?? null) === '250Mbit';
        });
    }
})->with('resourceUpdateDrivers');

it('returns Accepted for long-running async updates and resolves state via getOperationStatus', function (string $driverType): void {
    $initial = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(2, 1024, 20, 'container');

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $initial)
            ->setAsyncApplyLatency($this->vm, pollsBeforeComplete: 1, terminalState: OperationState::Succeeded);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::sequence()
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $initial),
                ], 200, ['ETag' => '"etag-1"'])
                ->push([
                    'type' => 'async',
                    'status' => 'Operation created',
                    'status_code' => 100,
                    'operation' => '/1.0/operations/op-resize-async-1',
                ], 202)
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $target),
                ], 200, ['ETag' => '"etag-2"']),
            'https://incus-contract.local:8443/1.0/operations/op-resize-async-1/wait*' => Http::response([
                'type' => 'sync',
                'metadata' => ['id' => 'op-resize-async-1', 'status' => 'Running', 'status_code' => 103],
            ], 408),
            'https://incus-contract.local:8443/1.0/operations/op-resize-async-1?project=tenant-contract-proj' => Http::sequence()
                ->push([
                    'type' => 'sync',
                    'metadata' => ['id' => 'op-resize-async-1', 'status' => 'Running', 'status_code' => 103],
                ], 200)
                ->push([
                    'type' => 'sync',
                    'metadata' => ['id' => 'op-resize-async-1', 'status' => 'Success', 'status_code' => 200],
                ], 200),
        ]);
    }

    $result = $driver->applyResourceUpdate($this->vm, $target);

    expect($result->status)->toBe(ResourceUpdateStatus::Accepted)
        ->and($result->operationId)->not->toBeNull()->not->toBe('');

    $operationId = (string) $result->operationId;

    $firstPoll = $driver->getOperationStatus($this->vm, $operationId);
    expect($firstPoll->state)->toBe(OperationState::Running);

    $secondPoll = $driver->getOperationStatus($this->vm, $operationId);
    expect($secondPoll->state)->toBe(OperationState::Succeeded)
        ->and($driver->getInstanceResourceSpec($this->vm))->toEqual($target);
})->with('resourceUpdateDrivers');

it('returns Rejected on ETag 412 concurrent modification', function (string $driverType): void {
    $initial = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(2, 1024, 10, 'container');

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $initial)
            ->setEtagConflict($this->vm, 1);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::sequence()
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $initial),
                ], 200, ['ETag' => '"stale-etag"'])
                ->push([
                    'type' => 'error',
                    'error' => 'ETag mismatch',
                    'error_code' => 412,
                ], 412),
        ]);
    }

    $result = $driver->applyResourceUpdate($this->vm, $target);

    expect($result->status)->toBe(ResourceUpdateStatus::Rejected)
        ->and($result->errorCode)->toBe('CONCURRENT_MODIFICATION');
})->with('resourceUpdateDrivers');

it('returns Rejected on project limit exceeded', function (string $driverType): void {
    $initial = new InstanceResourceSpec(1, 512, 10, 'container');
    $target = new InstanceResourceSpec(64, 131072, 10, 'container');

    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setInstanceResourceSpec($this->vm, $initial)
            ->setApplyOutcome(
                $this->vm,
                ResourceUpdateStatus::Rejected,
                'PROJECT_LIMIT_EXCEEDED',
                'Project CPU limit exceeded.',
            );
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/ct-contract-01?project=tenant-contract-proj' => Http::sequence()
                ->push([
                    'type' => 'sync',
                    'metadata' => buildContractIncusInstance($this->vm->name, $initial),
                ], 200, ['ETag' => '"etag-1"'])
                ->push([
                    'type' => 'error',
                    'error' => 'Reached maximum number of limits.cpu in project tenant-contract-proj',
                    'error_code' => 403,
                ], 403),
        ]);
    }

    $result = $driver->applyResourceUpdate($this->vm, $target);

    expect($result->status)->toBe(ResourceUpdateStatus::Rejected)
        ->and($result->errorCode)->toBe('PROJECT_LIMIT_EXCEEDED');
})->with('resourceUpdateDrivers');

it('returns Failed with DRIVER_UNAVAILABLE when hypervisor cannot be reached during applyResourceUpdate', function (string $driverType): void {
    $target = new InstanceResourceSpec(2, 1024, 10, 'container');
    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setHostUnreachable($this->vm);
    } else {
        Http::fake([
            'https://incus-contract.local:8443/1.0/instances/*' => static fn () => throw new ConnectionException('Connection refused'),
        ]);
    }

    $result = $driver->applyResourceUpdate($this->vm, $target);

    expect($result->status)->toBe(ResourceUpdateStatus::Failed)
        ->and($result->errorCode)->toBe('DRIVER_UNAVAILABLE');
})->with('resourceUpdateDrivers');

it('reports Failed and Unknown operation states consistently via getOperationStatus', function (string $driverType): void {
    $driver = makeContractDriver($driverType, $this->host);

    if ($driver instanceof FakeHypervisorDriver) {
        $driver->setAsyncApplyLatency(
            $this->vm,
            pollsBeforeComplete: 0,
            terminalState: OperationState::Failed,
            errorCode: 'HYPERVISOR_OPERATION_FAILED',
            safeMessage: 'Storage resize failed.',
        );
        $accepted = $driver->applyResourceUpdate($this->vm, new InstanceResourceSpec(2, 1024, 20, 'container'));
        $failedOpId = (string) $accepted->operationId;
    } else {
        $failedOpId = 'op-failed-99';
        Http::fake([
            'https://incus-contract.local:8443/1.0/operations/op-failed-99?project=tenant-contract-proj' => Http::response([
                'type' => 'sync',
                'metadata' => [
                    'id' => 'op-failed-99',
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'Storage resize failed.',
                ],
            ], 200),
            'https://incus-contract.local:8443/1.0/operations/op-missing-404?project=tenant-contract-proj' => Http::response([
                'type' => 'error',
                'error' => 'not found',
                'error_code' => 404,
            ], 404),
        ]);
    }

    $failedStatus = $driver->getOperationStatus($this->vm, $failedOpId);
    expect($failedStatus->state)->toBe(OperationState::Failed)
        ->and($failedStatus->errorCode)->toBe('HYPERVISOR_OPERATION_FAILED')
        ->and($failedStatus->safeMessage)->toContain('Storage resize failed');

    $unknownStatus = $driver->getOperationStatus($this->vm, 'op-missing-404');
    expect($unknownStatus->state)->toBe(OperationState::Unknown)
        ->and($unknownStatus->errorCode)->toBe('OPERATION_NOT_FOUND');
})->with('resourceUpdateDrivers');
