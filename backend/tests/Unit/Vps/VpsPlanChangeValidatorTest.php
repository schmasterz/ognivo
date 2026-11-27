<?php

declare(strict_types=1);

use App\Enums\Hypervisor\ResourceKind;
use App\Enums\Hypervisor\ResourceUpdateVerdict;
use App\Enums\IpAllocationStatus;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Vps\VpsPlanChangeValidator;
use App\States\Host\Ok as HostOk;
use App\States\Host\Unreachable as HostUnreachable;
use App\States\Vm\Provisioning as VmProvisioning;
use App\States\Vm\Running as VmRunning;
use App\States\Vm\Stopped as VmStopped;
use App\States\Vm\Suspended as VmSuspended;
use App\States\VpsPlan\Draft as PlanDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    HypervisorManager::resetFake();
});

/**
 * Create a published VPS plan with a published current version.
 *
 * @param  array<string, mixed>  $versionAttributes
 */
function makePublishedPlanWithVersion(array $versionAttributes = []): array
{
    $plan = VpsPlan::factory()->published()->create();
    $version = VpsPlanVersion::factory()->published()->create(array_merge([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ], $versionAttributes));

    $plan->current_version_id = $version->id;
    $plan->save();

    return [$plan->fresh(), $version->fresh()];
}

/**
 * Create a healthy active host with generous capacity and a routed IP pool.
 *
 * @param  array<string, mixed>  $hostAttributes
 */
function makeReadyHost(array $hostAttributes = [], string $networkMode = 'routed'): array
{
    $host = Host::factory()->create(array_merge([
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 32,
        'total_ram_mb' => 65536,
        'total_storage_gb' => 1000,
    ], $hostAttributes));

    $pool = IpPool::factory()->create([
        'host_id' => $host->id,
        'active' => true,
        'network_mode' => $networkMode,
    ]);

    return [$host, $pool];
}

/**
 * Create a user with generous default quota unless overridden.
 *
 * @param  array<string, mixed>  $quotaAttributes
 */
function makeUserWithQuota(array $quotaAttributes = []): User
{
    $user = User::factory()->create();
    UserQuota::query()->create(array_merge([
        'user_id' => $user->id,
        'max_vms' => 10,
        'max_cpu_cores' => 64,
        'max_ram_mb' => 131072,
        'max_storage_gb' => 2000,
        'max_ips' => 10,
        'max_snapshots' => 10,
    ], $quotaAttributes));

    return $user;
}

it('approves valid container resize, computes per-resource change directions, and emits disk resize warning', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host, $pool] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $currentVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 4,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => null,
        'net_upload_mbps' => 500,
    ]);
    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 40,
        'net_download_mbps' => 1000,
        'net_upload_mbps' => null,
    ]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $currentVersion->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 4,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => null,
        'net_upload_mbps' => 500,
    ]);

    IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'virtual_machine_id' => $vm->id,
        'user_id' => $user->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $targetVersion);

    expect($result['can_submit'])->toBeTrue()
        ->and($result['requires_shutdown'])->toBeFalse()
        ->and($result['blocking_reasons'])->toBe([])
        ->and($result['warnings'])->toBe(['DISK_GUEST_RESIZE_MAY_BE_REQUIRED'])
        ->and($result['changes'])->toBe([
            'cpu' => 'decrease',
            'ram' => 'unchanged',
            'disk' => 'increase',
            'net_download' => 'set',
            'net_upload' => 'remove',
        ]);
});

it('allows assigning a plan version when from_version_id is null even if resource columns match', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => null,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $targetVersion);

    expect($result['can_submit'])->toBeTrue()
        ->and($result['blocking_reasons'])->toBe([])
        ->and($result['changes'])->toBe([
            'cpu' => 'unchanged',
            'ram' => 'unchanged',
            'disk' => 'unchanged',
            'net_download' => 'unchanged',
            'net_upload' => 'unchanged',
        ]);
});

it('blocks NO_CHANGE when actual VM columns and vps_plan_version_id both match target version', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $version] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $version->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $version);

    expect($result['can_submit'])->toBeFalse()
        ->and(collect($result['blocking_reasons'])->pluck('code')->all())->toContain('NO_CHANGE');
});

it('blocks VM_STATE_NOT_SUPPORTED for provisioning or suspended VMs', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $targetVersion] = makePublishedPlanWithVersion(['cpu_cores' => 4]);

    foreach ([VmProvisioning::class, VmSuspended::class] as $stateClass) {
        $vm = VirtualMachine::factory()->create([
            'user_id' => $user->id,
            'host_id' => $host->id,
            'instance_type' => 'container',
            'status' => $stateClass,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
        ]);

        $result = app(VpsPlanChangeValidator::class)->validate($vm, $targetVersion);

        expect($result['can_submit'])->toBeFalse()
            ->and(collect($result['blocking_reasons'])->pluck('code')->all())->toContain('VM_STATE_NOT_SUPPORTED');
    }
});

it('blocks ANOTHER_CHANGE_IN_PROGRESS when an active plan change exists unless excluded', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $targetVersion] = makePublishedPlanWithVersion(['cpu_cores' => 4]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    $activeChange = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 20],
        'new_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 20],
    ]);

    $validator = app(VpsPlanChangeValidator::class);

    $blocked = $validator->validate($vm, $targetVersion);
    expect($blocked['can_submit'])->toBeFalse()
        ->and(collect($blocked['blocking_reasons'])->pluck('code')->all())->toContain('ANOTHER_CHANGE_IN_PROGRESS');

    $allowedWhenExcluded = $validator->validate($vm, $targetVersion, excludeChangeId: $activeChange->id);
    expect($allowedWhenExcluded['can_submit'])->toBeTrue();
});

it('enforces PLAN_NOT_AVAILABLE and VERSION_NOT_AVAILABLE rules for tenants and allows admin superseded targets', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    $validator = app(VpsPlanChangeValidator::class);

    // 1. Draft plan -> PLAN_NOT_AVAILABLE for tenant, VERSION_NOT_AVAILABLE for admin
    $draftPlan = VpsPlan::factory()->create(['status' => PlanDraft::class]);
    $draftPlanPublishedVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $draftPlan->id,
        'cpu_cores' => 4,
        'instance_type' => 'container',
    ]);
    expect(collect($validator->validate($vm, $draftPlanPublishedVersion, isAdmin: false)['blocking_reasons'])->pluck('code')->all())
        ->toContain('PLAN_NOT_AVAILABLE');
    expect(collect($validator->validate($vm, $draftPlanPublishedVersion, isAdmin: true)['blocking_reasons'])->pluck('code')->all())
        ->toContain('VERSION_NOT_AVAILABLE');

    // 2. Archived plan -> PLAN_NOT_AVAILABLE for tenant, VERSION_NOT_AVAILABLE for admin
    $archivedPlan = VpsPlan::factory()->archived()->create();
    $archivedPlanVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $archivedPlan->id,
        'cpu_cores' => 4,
        'instance_type' => 'container',
    ]);
    expect(collect($validator->validate($vm, $archivedPlanVersion, isAdmin: false)['blocking_reasons'])->pluck('code')->all())
        ->toContain('PLAN_NOT_AVAILABLE');
    expect(collect($validator->validate($vm, $archivedPlanVersion, isAdmin: true)['blocking_reasons'])->pluck('code')->all())
        ->toContain('VERSION_NOT_AVAILABLE');

    // 3. Published plan with superseded version -> VERSION_NOT_AVAILABLE for tenant, ALLOWED for admin
    $publishedPlan = VpsPlan::factory()->published()->create();
    $supersededVersion = VpsPlanVersion::factory()->superseded()->create([
        'vps_plan_id' => $publishedPlan->id,
        'version' => 1,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);
    $currentVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $publishedPlan->id,
        'version' => 2,
        'cpu_cores' => 6,
        'instance_type' => 'container',
    ]);
    $publishedPlan->update(['current_version_id' => $currentVersion->id]);

    expect(collect($validator->validate($vm, $supersededVersion, isAdmin: false)['blocking_reasons'])->pluck('code')->all())
        ->toContain('VERSION_NOT_AVAILABLE');

    $adminSupersededResult = $validator->validate($vm, $supersededVersion, isAdmin: true);
    expect($adminSupersededResult['can_submit'])->toBeTrue()
        ->and($adminSupersededResult['blocking_reasons'])->toBe([]);

    // 4. Draft version on published plan -> VERSION_NOT_AVAILABLE even for admin
    $draftVersion = VpsPlanVersion::factory()->create([
        'vps_plan_id' => $publishedPlan->id,
        'cpu_cores' => 4,
        'instance_type' => 'container',
    ]);
    expect(collect($validator->validate($vm, $draftVersion, isAdmin: true)['blocking_reasons'])->pluck('code')->all())
        ->toContain('VERSION_NOT_AVAILABLE');
});

it('blocks INSTANCE_TYPE_MISMATCH and DISK_SHRINK_NOT_SUPPORTED', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();
    [, $vmTarget] = makePublishedPlanWithVersion([
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 10,
        'instance_type' => 'virtual-machine',
    ]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 30,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $vmTarget);
    $codes = collect($result['blocking_reasons'])->pluck('code')->all();

    expect($result['can_submit'])->toBeFalse()
        ->and($codes)->toContain('INSTANCE_TYPE_MISMATCH')
        ->and($codes)->toContain('DISK_SHRINK_NOT_SUPPORTED')
        ->and($codes)->not->toContain('DRIVER_OPERATION_UNSUPPORTED');
});

it('blocks QUOTA_EXCEEDED per increasing resource including active reservations and respects unlimited quotas', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota([
        'max_cpu_cores' => 6,
        'max_ram_mb' => 6144,
        'max_storage_gb' => 50,
    ]);

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    $otherVm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 15,
    ]);

    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 30,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    // Active reservation on otherVm reserves +2 CPU, +1024 RAM, +10 Disk
    // Effective used before this change: 6 CPU, 5120 RAM, 45 Disk
    // Delta for $vm: +2 CPU, +2048 RAM, +10 Disk -> 8 > 6 CPU, 7168 > 6144 RAM, 55 > 50 Disk
    VpsPlanChange::factory()->create([
        'virtual_machine_id' => $otherVm->id,
        'user_id' => $user->id,
        'to_version_id' => $targetVersion->id,
        'old_resources' => ['cpu_cores' => 2, 'ram_mb' => 2048, 'disk_gb' => 15],
        'new_resources' => ['cpu_cores' => 4, 'ram_mb' => 3072, 'disk_gb' => 25],
    ]);

    $validator = app(VpsPlanChangeValidator::class);
    $result = $validator->validate($vm, $targetVersion);
    $quotaReasons = collect($result['blocking_reasons'])->where('code', 'QUOTA_EXCEEDED');

    expect($result['can_submit'])->toBeFalse()
        ->and($quotaReasons->pluck('kind')->sort()->values()->all())->toBe(['cpu', 'disk', 'ram']);

    // Unlimited quotas (-1) allow the increase
    $user->quota()->update([
        'max_cpu_cores' => -1,
        'max_ram_mb' => -1,
        'max_storage_gb' => -1,
    ]);

    expect($validator->validate($vm, $targetVersion)['can_submit'])->toBeTrue();
});

it('blocks HOST_CAPACITY_EXCEEDED when host capacity guard rejects the resize', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);
    config([
        'provisioning.host_cpu_reserve_cores' => 0,
        'provisioning.host_ram_reserve_mb' => 0,
        'provisioning.host_disk_reserve_gb' => 0,
    ]);

    [$host] = makeReadyHost([
        'total_cpu_cores' => 4,
        'total_ram_mb' => 4096,
        'total_storage_gb' => 100,
    ]);
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 6,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $targetVersion);

    expect($result['can_submit'])->toBeFalse()
        ->and(collect($result['blocking_reasons'])->pluck('code')->all())->toContain('HOST_CAPACITY_EXCEEDED');
});

it('blocks NETWORK_LIMIT_NOT_SUPPORTED_MODE when primary IP pool is in manual mode', function (): void {
    HypervisorManager::fake(new FakeHypervisorDriver);

    [$host, $manualPool] = makeReadyHost(networkMode: 'manual');
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    IpAllocation::factory()->create([
        'pool_id' => $manualPool->id,
        'virtual_machine_id' => $vm->id,
        'user_id' => $user->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    $result = app(VpsPlanChangeValidator::class)->validate($vm, $targetVersion);

    expect($result['can_submit'])->toBeFalse()
        ->and(collect($result['blocking_reasons'])->pluck('code')->all())->toContain('NETWORK_LIMIT_NOT_SUPPORTED_MODE');
});

it('blocks HOST_UNREACHABLE when host is inactive/unreachable in DB or driver throws DriverUnavailableException', function (): void {
    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    [$host] = makeReadyHost(['is_active' => false]);
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 4,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $validator = app(VpsPlanChangeValidator::class);

    // 1. Inactive host in DB
    expect(collect($validator->validate($vm, $targetVersion)['blocking_reasons'])->pluck('code')->all())
        ->toContain('HOST_UNREACHABLE');

    // 2. Host status Unreachable in DB
    $host->update(['is_active' => true, 'status' => HostUnreachable::class]);
    $vm->refresh();
    expect(collect($validator->validate($vm, $targetVersion)['blocking_reasons'])->pluck('code')->all())
        ->toContain('HOST_UNREACHABLE');

    // 3. Healthy DB host, but live driver reports host unreachable
    $host->update(['is_active' => true, 'status' => HostOk::class]);
    $vm->refresh();
    $fakeDriver->setHostUnreachable($host, true);

    expect(collect($validator->validate($vm, $targetVersion)['blocking_reasons'])->pluck('code')->all())
        ->toContain('HOST_UNREACHABLE');
});

it('blocks REQUIRES_SHUTDOWN_NOT_SUPPORTED on running virtual-machine resize and allows when stopped or skipped', function (): void {
    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    [$host] = makeReadyHost();
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'virtual-machine',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    [, $targetVersion] = makePublishedPlanWithVersion([
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 20,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $validator = app(VpsPlanChangeValidator::class);

    // Running VM -> RequiresStop -> REQUIRES_SHUTDOWN_NOT_SUPPORTED + requires_shutdown = true
    $runningResult = $validator->validate($vm, $targetVersion);
    expect($runningResult['can_submit'])->toBeFalse()
        ->and($runningResult['requires_shutdown'])->toBeTrue()
        ->and(collect($runningResult['blocking_reasons'])->pluck('code')->all())->toContain('REQUIRES_SHUTDOWN_NOT_SUPPORTED');

    // With skipDriverAssessment = true, driver check is skipped
    $skippedResult = $validator->validate($vm, $targetVersion, skipDriverAssessment: true);
    expect($skippedResult['can_submit'])->toBeTrue()
        ->and($skippedResult['requires_shutdown'])->toBeFalse();

    // Stopped VM -> Online -> allowed
    $vm->update(['status' => VmStopped::class]);
    $fakeDriver->setInstanceStatus($vm, 'Stopped');
    $stoppedResult = $validator->validate($vm->fresh(), $targetVersion);
    expect($stoppedResult['can_submit'])->toBeTrue()
        ->and($stoppedResult['requires_shutdown'])->toBeFalse();
});

it('maps NETWORK_LIMIT_NO_NIC and other Unsupported driver verdicts to blocking codes', function (): void {
    $fakeDriver = new FakeHypervisorDriver;
    HypervisorManager::fake($fakeDriver);

    [$host, $pool] = makeReadyHost();
    $user = makeUserWithQuota();

    $vm = VirtualMachine::factory()->create([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
    ]);

    IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'virtual_machine_id' => $vm->id,
        'user_id' => $user->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    [, $netTarget] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);

    $validator = app(VpsPlanChangeValidator::class);

    // 1. Missing NIC device -> NETWORK_LIMIT_NO_NIC
    $fakeDriver->setInstanceNicType($vm, null);
    $noNicResult = $validator->validate($vm, $netTarget);
    expect($noNicResult['can_submit'])->toBeFalse()
        ->and(collect($noNicResult['blocking_reasons'])->pluck('code')->all())->toBe(['NETWORK_LIMIT_NO_NIC']);

    // 2. Other unsupported verdict (e.g. STORAGE_QUOTA_UNSUPPORTED for container disk resize on dir)
    $fakeDriver->setInstanceNicType($vm, 'routed');
    $fakeDriver->setAssessmentOverrides(
        $vm,
        [ResourceKind::Disk->value => ResourceUpdateVerdict::Unsupported],
        [ResourceKind::Disk->value => 'STORAGE_QUOTA_UNSUPPORTED'],
    );
    [, $diskTarget] = makePublishedPlanWithVersion([
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 40,
        'instance_type' => 'container',
        'net_download_mbps' => null,
        'net_upload_mbps' => null,
    ]);

    $unsupportedResult = $validator->validate($vm, $diskTarget);
    expect($unsupportedResult['can_submit'])->toBeFalse()
        ->and($unsupportedResult['blocking_reasons'])->toHaveCount(1)
        ->and($unsupportedResult['blocking_reasons'][0]['code'])->toBe('DRIVER_OPERATION_UNSUPPORTED')
        ->and($unsupportedResult['blocking_reasons'][0]['kind'])->toBe('disk');
});
