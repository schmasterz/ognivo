<?php

declare(strict_types=1);

use App\DTO\Host\CreateHostDTO;
use App\DTO\Host\UpdateHostDTO;
use App\DTO\ImageSelectionDTO;
use App\Events\Host\HostMaintenanceEntered;
use App\Events\Host\HostMaintenanceExited;
use App\Events\Host\HostRegistered;
use App\Events\Host\HostStatusUpdated;
use App\Events\Instance\InstanceRebooted;
use App\Events\Instance\InstanceStarted;
use App\Events\Instance\InstanceStopped;
use App\Events\Instance\InstanceTerminated;
use App\Events\SnapshotStatusUpdated;
use App\Events\Storage\SnapshotCreated;
use App\Exceptions\DuplicateSnapshotNameException;
use App\Models\Host;
use App\Models\IpPool;
use App\Models\QuotaGroup;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VmSnapshot;
use App\Services\HostNodeService;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmLifecycleService;
use App\Services\VmSnapshotService;
use App\States\Vm\Deleted;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $this->fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($this->fakeDriver);
});

afterEach(function (): void {
    HypervisorManager::resetFake();
});

function createVmFixture(User $owner, Host $host, string $statusClass = Stopped::class, string $name = 'event-vm-01'): VirtualMachine
{
    /** @var VirtualMachine $vm */
    $vm = VirtualMachine::create([
        'user_id' => $owner->id,
        'host_id' => $host->id,
        'name' => $name,
        'incus_project' => 'default',
        'instance_type' => 'virtual-machine',
        'status' => $statusClass,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'os_image' => 'images:ubuntu/24.04',
    ]);

    return $vm;
}

function createCustomerAndHostFixture(): array
{
    $defaultGroup = QuotaGroup::where('is_default', true)->firstOrFail();

    /** @var User $customer */
    $customer = User::factory()->create([
        'status' => 'active',
        'quota_group_id' => $defaultGroup->id,
    ]);
    $customer->assignRole('customer');

    UserQuota::create([
        'user_id' => $customer->id,
        'quota_group_id' => $defaultGroup->id,
        'max_vms' => 5,
        'max_cpu_cores' => 16,
        'max_ram_mb' => 16384,
        'max_storage_gb' => 200,
        'max_ips' => 5,
        'max_snapshots' => 5,
    ]);

    /** @var Host $host */
    $host = Host::create([
        'name' => 'events-host-01',
        'endpoint_url' => 'https://10.10.0.10:8443',
        'driver' => 'fake',
        'status' => 'ok',
        'is_active' => true,
        'total_cpu_cores' => 32,
        'total_ram_mb' => 65536,
        'total_storage_gb' => 2000,
        'capacity_source' => 'discovered',
    ]);

    IpPool::create([
        'host_id' => $host->id,
        'name' => 'Events Test Pool',
        'subnet' => '10.10.0.0/24',
        'gateway' => '10.10.0.1',
        'ip_version' => 4,
        'dns_servers' => ['8.8.8.8'],
        'active' => true,
    ]);

    return [$customer, $host];
}

it('dispatches InstanceStarted, InstanceStopped, InstanceRebooted, and InstanceTerminated on VmLifecycleService operations', function (): void {
    Event::fake([
        InstanceStarted::class,
        InstanceStopped::class,
        InstanceRebooted::class,
        InstanceTerminated::class,
    ]);

    [$customer, $host] = createCustomerAndHostFixture();
    $vm = createVmFixture($customer, $host, Stopped::class, 'lifecycle-events-vm');

    /** @var VmLifecycleService $lifecycle */
    $lifecycle = app(VmLifecycleService::class);

    expect($lifecycle->start($vm))->toBeTrue();
    Event::assertDispatched(InstanceStarted::class, fn (InstanceStarted $event): bool => $event->instanceId === $vm->id
        && $event->name === 'lifecycle-events-vm'
        && $event->status === 'running');

    expect($lifecycle->stop($vm))->toBeTrue();
    Event::assertDispatched(InstanceStopped::class, fn (InstanceStopped $event): bool => $event->instanceId === $vm->id
        && $event->name === 'lifecycle-events-vm'
        && $event->status === 'stopped');

    expect($lifecycle->restart($vm))->toBeTrue();
    Event::assertDispatched(InstanceRebooted::class, fn (InstanceRebooted $event): bool => $event->instanceId === $vm->id
        && $event->name === 'lifecycle-events-vm'
        && $event->status === 'running');

    expect($lifecycle->delete($vm))->toBeTrue();
    Event::assertDispatchedTimes(InstanceTerminated::class, 1);
    Event::assertDispatched(InstanceTerminated::class, fn (InstanceTerminated $event): bool => $event->instanceId === $vm->id
        && $event->name === 'lifecycle-events-vm');

    // Deleting an already-deleted VM returns early without re-dispatching InstanceTerminated.
    expect($lifecycle->delete($vm->fresh() ?? $vm))->toBeTrue();
    Event::assertDispatchedTimes(InstanceTerminated::class, 1);
});

it('does not dispatch instance lifecycle events when hypervisor fails or transaction rolls back', function (): void {
    Event::fake([
        InstanceStarted::class,
        InstanceStopped::class,
        InstanceRebooted::class,
        InstanceTerminated::class,
    ]);

    [$customer, $host] = createCustomerAndHostFixture();
    $vm = createVmFixture($customer, $host, Stopped::class, 'rollback-vm');

    /** @var VmLifecycleService $lifecycle */
    $lifecycle = app(VmLifecycleService::class);

    $this->fakeDriver->setFailAction('startInstance', true);
    expect(fn (): bool => $lifecycle->start($vm))->toThrow(RuntimeException::class);
    Event::assertNotDispatched(InstanceStarted::class);
    $this->fakeDriver->setFailAction('startInstance', false);

    expect(function () use ($lifecycle, $vm): void {
        DB::transaction(function () use ($lifecycle, $vm): void {
            $lifecycle->start($vm);
            $lifecycle->stop($vm);
            $lifecycle->restart($vm);
            $lifecycle->delete($vm);

            throw new RuntimeException('Simulated transaction failure');
        });
    })->toThrow(RuntimeException::class, 'Simulated transaction failure');

    Event::assertNotDispatched(InstanceStarted::class);
    Event::assertNotDispatched(InstanceStopped::class);
    Event::assertNotDispatched(InstanceRebooted::class);
    Event::assertNotDispatched(InstanceTerminated::class);
    expect($vm->fresh()?->status->equals(Stopped::class))->toBeTrue();
});

it('dispatches HostRegistered with IP or hostname fallback on HostNodeService::create', function (): void {
    Event::fake([HostRegistered::class]);

    /** @var HostNodeService $hostService */
    $hostService = app(HostNodeService::class);

    $ipHost = $hostService->create(new CreateHostDTO(
        name: 'ip-node-01',
        endpointUrl: 'https://10.20.30.40:8443',
        driver: 'fake',
    ));

    Event::assertDispatched(HostRegistered::class, fn (HostRegistered $event): bool => $event->hostId === $ipHost->id
        && $event->name === 'ip-node-01'
        && $event->hostname === '10.20.30.40'
        && $event->ipAddress === '10.20.30.40');

    $dnsHost = $hostService->create(new CreateHostDTO(
        name: 'dns-node-01',
        endpointUrl: 'https://compute-01.example.com:8443',
        driver: 'fake',
    ));

    Event::assertDispatched(HostRegistered::class, fn (HostRegistered $event): bool => $event->hostId === $dnsHost->id
        && $event->name === 'dns-node-01'
        && $event->hostname === 'compute-01.example.com'
        && $event->ipAddress === '');
});

it('dispatches HostMaintenanceEntered, HostMaintenanceExited, and HostStatusUpdated in HostNodeService', function (): void {
    Event::fake([
        HostMaintenanceEntered::class,
        HostMaintenanceExited::class,
        HostStatusUpdated::class,
    ]);

    [, $host] = createCustomerAndHostFixture();

    /** @var HostNodeService $hostService */
    $hostService = app(HostNodeService::class);

    // 1. Deactivating host without image changes enters maintenance.
    $host = $hostService->update($host, new UpdateHostDTO(
        name: $host->name,
        endpointUrl: $host->endpoint_url,
        isActive: false,
    ));

    Event::assertDispatched(HostMaintenanceEntered::class, fn (HostMaintenanceEntered $event): bool => $event->hostId === $host->id
        && $event->reason === 'Deactivated');
    Event::assertNotDispatched(HostMaintenanceExited::class);

    // 2. Updating without changing isActive emits no maintenance event.
    $host = $hostService->update($host, new UpdateHostDTO(
        name: $host->name,
        endpointUrl: $host->endpoint_url,
        isActive: false,
    ));
    Event::assertDispatchedTimes(HostMaintenanceEntered::class, 1);
    Event::assertNotDispatched(HostMaintenanceExited::class);

    // 3. Re-activating host with an ImageSelectionDTO exits maintenance.
    $host = $hostService->update($host, new UpdateHostDTO(
        name: $host->name,
        endpointUrl: $host->endpoint_url,
        isActive: true,
        images: ImageSelectionDTO::all(),
    ));

    Event::assertDispatched(HostMaintenanceExited::class, fn (HostMaintenanceExited $event): bool => $event->hostId === $host->id);

    // 4. Updating operational status dispatches HostStatusUpdated only when changed.
    $hostService->updateStatus($host, 'unreachable');
    Event::assertDispatched(HostStatusUpdated::class, fn (HostStatusUpdated $event): bool => $event->hostId === $host->id
        && $event->oldStatus === 'ok'
        && $event->newStatus === 'unreachable');

    $hostService->updateStatus($host, 'unreachable');
    Event::assertDispatchedTimes(HostStatusUpdated::class, 1);
});

it('does not dispatch host events when transactions roll back', function (): void {
    Event::fake([
        HostRegistered::class,
        HostMaintenanceEntered::class,
        HostMaintenanceExited::class,
        HostStatusUpdated::class,
    ]);

    [, $host] = createCustomerAndHostFixture();

    /** @var HostNodeService $hostService */
    $hostService = app(HostNodeService::class);

    expect(function () use ($hostService, $host): void {
        DB::transaction(function () use ($hostService, $host): void {
            $hostService->create(new CreateHostDTO(
                name: 'rolled-back-host',
                endpointUrl: 'https://10.99.0.1:8443',
                driver: 'fake',
            ));

            $hostService->update($host, new UpdateHostDTO(
                name: $host->name,
                endpointUrl: $host->endpoint_url,
                isActive: false,
            ));

            $hostService->updateStatus($host, 'unreachable');

            throw new RuntimeException('Simulated host transaction failure');
        });
    })->toThrow(RuntimeException::class, 'Simulated host transaction failure');

    Event::assertNotDispatched(HostRegistered::class);
    Event::assertNotDispatched(HostMaintenanceEntered::class);
    Event::assertNotDispatched(HostMaintenanceExited::class);
    Event::assertNotDispatched(HostStatusUpdated::class);
    expect(Host::where('name', 'rolled-back-host')->exists())->toBeFalse();
    expect($host->fresh()?->status->getValue())->toBe('ok');
    expect($host->fresh()?->is_active)->toBeTrue();
});

it('dispatches SnapshotCreated on VmSnapshotService::create and suppresses it on failure or rollback', function (): void {
    Queue::fake();
    Event::fake([
        SnapshotCreated::class,
        SnapshotStatusUpdated::class,
    ]);

    [$customer, $host] = createCustomerAndHostFixture();
    $vm = createVmFixture($customer, $host, Running::class, 'snapshot-events-vm');

    /** @var VmSnapshotService $snapshotService */
    $snapshotService = app(VmSnapshotService::class);

    $snapshot = $snapshotService->create($vm, 'pre-upgrade-01');

    Event::assertDispatched(SnapshotCreated::class, fn (SnapshotCreated $event): bool => $event->snapshotId === $snapshot->id
        && $event->instanceId === $vm->id
        && $event->name === 'pre-upgrade-01');

    // Duplicate name throws inside DB::transaction and must not dispatch SnapshotCreated again.
    expect(fn (): VmSnapshot => $snapshotService->create($vm, 'pre-upgrade-01'))
        ->toThrow(DuplicateSnapshotNameException::class);
    Event::assertDispatchedTimes(SnapshotCreated::class, 1);

    // Outer transaction rollback suppresses SnapshotCreated.
    expect(function () use ($snapshotService, $vm): void {
        DB::transaction(function () use ($snapshotService, $vm): void {
            $snapshotService->create($vm, 'rolled-back-snap');

            throw new RuntimeException('Simulated snapshot transaction failure');
        });
    })->toThrow(RuntimeException::class, 'Simulated snapshot transaction failure');

    Event::assertDispatchedTimes(SnapshotCreated::class, 1);
    expect(VmSnapshot::where('name', 'rolled-back-snap')->exists())->toBeFalse();
});

it('delivers real ShouldDispatchAfterCommit events only when transactions commit', function (): void {
    [$customer, $host] = createCustomerAndHostFixture();
    $vm = createVmFixture($customer, $host, Stopped::class, 'real-dispatch-vm');

    /** @var list<InstanceStarted> $captured */
    $captured = [];
    Event::listen(InstanceStarted::class, function (InstanceStarted $event) use (&$captured): void {
        $captured[] = $event;
    });

    /** @var VmLifecycleService $lifecycle */
    $lifecycle = app(VmLifecycleService::class);

    expect(function () use ($lifecycle, $vm): void {
        DB::transaction(function () use ($lifecycle, $vm): void {
            $lifecycle->start($vm);

            throw new RuntimeException('Abort before commit');
        });
    })->toThrow(RuntimeException::class, 'Abort before commit');

    expect($captured)->toBeEmpty();

    $lifecycle->start($vm);

    expect($captured)->toHaveCount(1)
        ->and($captured[0]->instanceId)->toBe($vm->id)
        ->and($captured[0]->name)->toBe('real-dispatch-vm')
        ->and($captured[0]->status)->toBe('running');
});
