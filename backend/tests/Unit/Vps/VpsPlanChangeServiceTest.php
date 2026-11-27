<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use App\DTO\Hypervisor\OperationState;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateResult;
use App\DTO\Hypervisor\ResourceUpdateStatus;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\Enums\IpAllocationStatus;
use App\Events\Instance\InstanceResized;
use App\Events\Vps\VpsPlanChangeCancelled;
use App\Events\Vps\VpsPlanChangeCompleted;
use App\Events\Vps\VpsPlanChangeFailed;
use App\Events\Vps\VpsPlanChangeReconciliationRequired;
use App\Events\Vps\VpsPlanChangeRequested;
use App\Events\Vps\VpsPlanChangeStarted;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Jobs\ExecuteVmActionJob;
use App\Models\DriftReport;
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
use App\Services\VmLifecycleService;
use App\Services\Vps\VpsPlanChangeService;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Running as VmRunning;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

afterEach(function (): void {
    HypervisorManager::resetFake();
});

/**
 * @return array{
 *     user: User,
 *     admin: User,
 *     host: Host,
 *     fromVersion: VpsPlanVersion,
 *     toVersion: VpsPlanVersion,
 *     vm: VirtualMachine,
 *     driver: FakeHypervisorDriver,
 * }
 */
function setupPlanChangeEnvironment(array $vmOverrides = [], array $toVersionOverrides = []): array
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('customer');

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    UserQuota::query()->create([
        'user_id' => $user->id,
        'max_vms' => 10,
        'max_cpu_cores' => 64,
        'max_ram_mb' => 131072,
        'max_storage_gb' => 2000,
        'max_ips' => 10,
    ]);

    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 32,
        'total_ram_mb' => 65536,
        'total_storage_gb' => 1000,
    ]);

    $pool = IpPool::factory()->create([
        'host_id' => $host->id,
        'active' => true,
        'network_mode' => 'routed',
    ]);

    $plan = VpsPlan::factory()->published()->create();

    $fromVersion = VpsPlanVersion::factory()->superseded()->create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    $toVersion = VpsPlanVersion::factory()->published()->create(array_merge([
        'vps_plan_id' => $plan->id,
        'version' => 2,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ], $toVersionOverrides));

    $plan->current_version_id = $toVersion->id;
    $plan->save();

    $vm = VirtualMachine::factory()->create(array_merge([
        'user_id' => $user->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $fromVersion->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ], $vmOverrides));

    IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'user_id' => $user->id,
        'virtual_machine_id' => $vm->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    /** @var FakeHypervisorDriver $driver */
    $driver = HypervisorManager::fake();
    $driver->setInstanceStatus($vm, 'Running');
    $driver->setInstanceResourceSpec($vm, new InstanceResourceSpec(
        cpuCores: (int) $vm->cpu_cores,
        ramMb: (int) $vm->ram_mb,
        diskGb: (int) $vm->disk_gb,
        instanceType: (string) $vm->instance_type,
        network: ($vm->net_download_mbps !== null || $vm->net_upload_mbps !== null)
            ? new NetworkLimitsSpec($vm->net_download_mbps, $vm->net_upload_mbps)
            : null,
    ));

    return [
        'user' => $user,
        'admin' => $admin,
        'host' => $host,
        'fromVersion' => $fromVersion,
        'toVersion' => $toVersion,
        'vm' => $vm->fresh(['host', 'primaryAllocation.pool']),
        'driver' => $driver,
    ];
}

it('requests a plan change under locks, records audit, and dispatches events and job after commit', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeRequested::class]);

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    expect(Str::isUlid($change->public_id))->toBeTrue()
        ->and($change->status)->toBeInstanceOf(Pending::class)
        ->and($change->from_version_id)->toBe($env['fromVersion']->id)
        ->and($change->to_version_id)->toBe($env['toVersion']->id)
        ->and($change->initiated_by)->toBe($env['user']->id)
        ->and($change->requested_at)->not->toBeNull()
        ->and($change->old_resources)->toBe([
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'net_download_mbps' => 500,
            'net_upload_mbps' => 500,
        ])
        ->and($change->new_resources)->toBe([
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'net_download_mbps' => 1000,
            'net_upload_mbps' => 1000,
        ]);

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, fn (ApplyVpsPlanChangeJob $job): bool => $job->planChangeId === $change->id);

    Event::assertDispatched(VpsPlanChangeRequested::class, function (VpsPlanChangeRequested $event) use ($change): bool {
        $payload = $event->getPayload();

        return $event->getEventName() === 'vps.plan_change.requested'
            && $payload['vps_plan_change_id'] === $change->id
            && $payload['public_id'] === $change->public_id;
    });

    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeRequested->value)->count())->toBe(1);

    // Concurrent second request on same VM is denied with 409 and audit entry
    try {
        $service->request($env['vm'], $env['toVersion'], $env['user']);
        $this->fail('Expected ValidationException for concurrent active plan change.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(409);
    }

    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeDenied->value)->count())->toBe(1);
});

it('cancels a pending plan change and rejects cancelling non-cancellable states with 409', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeRequested::class, VpsPlanChangeCancelled::class]);

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);
    $cancelled = $service->cancel($change, $env['user']);

    expect($cancelled->status)->toBeInstanceOf(Cancelled::class);

    Event::assertDispatched(VpsPlanChangeCancelled::class, fn (VpsPlanChangeCancelled $e): bool => $e->planChangeId === $change->id);
    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeCancelled->value)->count())->toBe(1);

    // Cancelling again throws 409 ConflictHttpException
    expect(fn () => $service->cancel($cancelled, $env['user']))->toThrow(ConflictHttpException::class);

    // Cancelling an Applying plan change throws 409 ConflictHttpException
    $applying = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['user']->id,
        'to_version_id' => $env['toVersion']->id,
    ]);
    expect(fn () => $service->cancel($applying, $env['user']))->toThrow(ConflictHttpException::class);
});

it('applies a synchronous resource update, commits VM columns, and dispatches VpsPlanChangeCompleted and InstanceResized', function (): void {
    Queue::fake();
    Event::fake([
        VpsPlanChangeRequested::class,
        VpsPlanChangeStarted::class,
        VpsPlanChangeCompleted::class,
        InstanceResized::class,
    ]);

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    $job = new ApplyVpsPlanChangeJob($change->id, $env['vm']->id);
    $job->handle($service, app(HypervisorManager::class));

    $freshChange = $change->fresh();
    $freshVm = $env['vm']->fresh();

    expect($freshChange)->not->toBeNull()
        ->and($freshChange?->status)->toBeInstanceOf(Completed::class)
        ->and($freshChange?->completed_at)->not->toBeNull()
        ->and($freshVm?->cpu_cores)->toBe(4)
        ->and($freshVm?->ram_mb)->toBe(4096)
        ->and($freshVm?->disk_gb)->toBe(40)
        ->and($freshVm?->net_download_mbps)->toBe(1000)
        ->and($freshVm?->net_upload_mbps)->toBe(1000)
        ->and($freshVm?->vps_plan_version_id)->toBe($env['toVersion']->id);

    Event::assertDispatched(VpsPlanChangeStarted::class, fn (VpsPlanChangeStarted $e): bool => $e->planChangeId === $change->id);
    Event::assertDispatched(VpsPlanChangeCompleted::class, fn (VpsPlanChangeCompleted $e): bool => $e->planChangeId === $change->id);
    Event::assertDispatched(InstanceResized::class, function (InstanceResized $event) use ($env, $change): bool {
        $payload = $event->getPayload();

        return $payload['instance_id'] === $env['vm']->id
            && $payload['operation_id'] === $change->public_id
            && $payload['from_plan_version_id'] === $env['fromVersion']->id
            && $payload['to_plan_version_id'] === $env['toVersion']->id
            && $payload['old_specs'] === [
                'cpu' => 2,
                'memory_mb' => 2048,
                'disk_gb' => 20,
                'net_download_mbps' => 500,
                'net_upload_mbps' => 500,
            ]
            && $payload['new_specs'] === [
                'cpu' => 4,
                'memory_mb' => 4096,
                'disk_gb' => 40,
                'net_download_mbps' => 1000,
                'net_upload_mbps' => 1000,
            ];
    });

    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeCompleted->value)->count())->toBe(1);
});

it('polls Accepted async operations until Succeeded and then verifies and commits', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeStarted::class, VpsPlanChangeCompleted::class, InstanceResized::class]);

    $env = setupPlanChangeEnvironment();
    $env['driver']->setAsyncApplyLatency($env['vm'], 2, OperationState::Succeeded);

    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);
    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    $job = new ApplyVpsPlanChangeJob($change->id, $env['vm']->id);

    // 1st run: Pending -> Applying + Accepted (stores driver_operation_id)
    $job->handle($service, $manager);
    $afterApply = $change->fresh();
    expect($afterApply?->status)->toBeInstanceOf(Applying::class)
        ->and($afterApply?->driver_operation_id)->not->toBeNull();

    // 2nd & 3rd run: Running (polls_remaining 2 -> 1 -> 0)
    $job->handle($service, $manager);
    expect($change->fresh()?->status)->toBeInstanceOf(Applying::class);

    $job->handle($service, $manager);
    expect($change->fresh()?->status)->toBeInstanceOf(Applying::class);

    // 4th run: Succeeded -> verifying -> Completed
    $job->handle($service, $manager);

    expect($change->fresh()?->status)->toBeInstanceOf(Completed::class)
        ->and($env['vm']->fresh()?->vps_plan_version_id)->toBe($env['toVersion']->id);

    Event::assertDispatched(VpsPlanChangeCompleted::class);
    Event::assertDispatched(InstanceResized::class);
});

it('retries ETag CONCURRENT_MODIFICATION up to 3 times and fails when exhausted', function (): void {
    Queue::fake();

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    // 3 conflicts succeed on the 4th attempt (initial + 3 retries)
    $env['driver']->setEtagConflict($env['vm'], 3);
    $change1 = $service->request($env['vm'], $env['toVersion'], $env['user']);
    (new ApplyVpsPlanChangeJob($change1->id, $env['vm']->id))->handle($service, $manager);
    expect($change1->fresh()?->status)->toBeInstanceOf(Completed::class);

    // Reset VM to old version for second test with 4 conflicts (exceeds 3 retries)
    $plan2 = VpsPlan::factory()->published()->create();
    $nextVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $plan2->id,
        'version' => 1,
        'cpu_cores' => 6,
        'ram_mb' => 6144,
        'disk_gb' => 60,
        'instance_type' => 'container',
        'net_download_mbps' => 2000,
        'net_upload_mbps' => 2000,
    ]);
    $plan2->current_version_id = $nextVersion->id;
    $plan2->save();

    $env['driver']->setEtagConflict($env['vm']->fresh(), 4);
    $change2 = $service->request($env['vm']->fresh(['host', 'primaryAllocation.pool']), $nextVersion, $env['user']);
    (new ApplyVpsPlanChangeJob($change2->id, $env['vm']->id))->handle($service, $manager);

    $failed = $change2->fresh();
    expect($failed?->status)->toBeInstanceOf(Failed::class)
        ->and($failed?->error_code)->toBe('CONCURRENT_MODIFICATION');
});

it('transitions Rejected outcomes to Failed', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeFailed::class]);

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    $env['driver']->setApplyOutcome(
        $env['vm'],
        ResourceUpdateStatus::Rejected,
        'PROJECT_LIMIT_EXCEEDED',
        'Incus project limit exceeded.',
    );

    (new ApplyVpsPlanChangeJob($change->id, $env['vm']->id))->handle($service, $manager);

    $failed = $change->fresh();
    expect($failed?->status)->toBeInstanceOf(Failed::class)
        ->and($failed?->error_code)->toBe('PROJECT_LIMIT_EXCEEDED')
        ->and($env['vm']->fresh()?->vps_plan_version_id)->toBe($env['fromVersion']->id);

    Event::assertDispatched(VpsPlanChangeFailed::class);
});

it('handles T8 validation failure in ApplyVpsPlanChangeJob by transitioning to Failed', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeFailed::class]);

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    // Simulate driver capability changing before job executes
    $env['driver']->setAssessmentOverrides($env['vm'], [
        ResourceKind::Disk->value => ResourceUpdateVerdict::Unsupported,
    ]);

    (new ApplyVpsPlanChangeJob($change->id, $env['vm']->id))->handle($service, $manager);

    expect($change->fresh()?->status)->toBeInstanceOf(Failed::class);
    Event::assertDispatched(VpsPlanChangeFailed::class);
});

it('inspects hypervisor spec after Failed or Unknown apply outcome and routes to Failed, Completed, or ReconciliationRequired', function (): void {
    Queue::fake();

    // 1. Observed == old -> Failed
    $env1 = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $changeOld = $service->request($env1['vm'], $env1['toVersion'], $env1['user']);
    $env1['driver']->setApplyOutcome($env1['vm'], ResourceUpdateStatus::Unknown, 'CONNECTION_ERROR', 'Timeout');
    (new ApplyVpsPlanChangeJob($changeOld->id, $env1['vm']->id))->handle($service, $manager);
    expect($changeOld->fresh()?->status)->toBeInstanceOf(Failed::class)
        ->and($env1['vm']->fresh()?->vps_plan_version_id)->toBe($env1['fromVersion']->id);

    // 2. Observed == new -> commitCompleted
    HypervisorManager::resetFake();
    $env2 = setupPlanChangeEnvironment();
    $changeNew = $service->request($env2['vm'], $env2['toVersion'], $env2['user']);
    $env2['driver']->setInstanceResourceSpec($env2['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'container',
        network: new NetworkLimitsSpec(1000, 1000),
    ));
    $env2['driver']->setApplyOutcome($env2['vm'], ResourceUpdateStatus::Failed, 'CONNECTION_DROPPED', 'Lost socket');
    (new ApplyVpsPlanChangeJob($changeNew->id, $env2['vm']->id))->handle($service, $manager);
    expect($changeNew->fresh()?->status)->toBeInstanceOf(Completed::class)
        ->and($env2['vm']->fresh()?->vps_plan_version_id)->toBe($env2['toVersion']->id);

    // 3. Observed == partial -> ReconciliationRequired + DriftReport(kind: resource_mismatch)
    HypervisorManager::resetFake();
    Event::fake([VpsPlanChangeReconciliationRequired::class]);
    $env3 = setupPlanChangeEnvironment();
    $changePartial = $service->request($env3['vm'], $env3['toVersion'], $env3['user']);
    $env3['driver']->setPartialApplySpec(
        $env3['vm'],
        new InstanceResourceSpec(
            cpuCores: 4,
            ramMb: 2048,
            diskGb: 20,
            instanceType: 'container',
            network: new NetworkLimitsSpec(500, 500),
        ),
        new ResourceUpdateResult(ResourceUpdateStatus::Failed, null, 'PARTIAL_APPLY', 'Partial update'),
    );
    (new ApplyVpsPlanChangeJob($changePartial->id, $env3['vm']->id))->handle($service, $manager);

    $reconciling = $changePartial->fresh();
    expect($reconciling?->status)->toBeInstanceOf(ReconciliationRequired::class);
    Event::assertDispatched(VpsPlanChangeReconciliationRequired::class);

    $drift = DriftReport::query()
        ->where('virtual_machine_id', $env3['vm']->id)
        ->where('kind', 'resource_mismatch')
        ->first();
    expect($drift)->not->toBeNull()
        ->and($drift?->resolved_at)->toBeNull();
});

it('commits vps_plan_version_id without applyResourceUpdate for from_version_id = null assignment when resources match', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeCompleted::class, InstanceResized::class]);

    $env = setupPlanChangeEnvironment(
        vmOverrides: [
            'vps_plan_version_id' => null,
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'net_download_mbps' => 1000,
            'net_upload_mbps' => 1000,
        ],
    );

    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);
    expect($change->from_version_id)->toBeNull();

    (new ApplyVpsPlanChangeJob($change->id, $env['vm']->id))->handle($service, $manager);

    expect($change->fresh()?->status)->toBeInstanceOf(Completed::class)
        ->and($env['vm']->fresh()?->vps_plan_version_id)->toBe($env['toVersion']->id)
        ->and($env['driver']->appliedResourceUpdates)->toBeEmpty();

    Event::assertDispatched(VpsPlanChangeCompleted::class);
    Event::assertDispatched(InstanceResized::class, fn (InstanceResized $e): bool => $e->fromPlanVersionId === null && $e->toPlanVersionId === $env['toVersion']->id);
});

it('exits ApplyVpsPlanChangeJob as a no-op when change is already in an unexpected state', function (): void {
    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $cancelled = VpsPlanChange::factory()->cancelled()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['user']->id,
        'to_version_id' => $env['toVersion']->id,
    ]);

    (new ApplyVpsPlanChangeJob($cancelled->id, $env['vm']->id))->handle($service, $manager);

    expect($cancelled->fresh()?->status)->toBeInstanceOf(Cancelled::class)
        ->and($env['driver']->appliedResourceUpdates)->toBeEmpty();
});

it('resolves reconciliation_required plan changes with complete, fail, and retry validated against hypervisor spec', function (): void {
    Queue::fake();

    $env = setupPlanChangeEnvironment();
    $service = app(VpsPlanChangeService::class);

    $change = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['user']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => [
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'net_download_mbps' => 500,
            'net_upload_mbps' => 500,
        ],
        'new_resources' => [
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'net_download_mbps' => 1000,
            'net_upload_mbps' => 1000,
        ],
    ]);

    // Attempting 'complete' when hypervisor still has old specs fails validation
    expect(fn () => $service->resolve($change, 'complete', 'Should fail', $env['admin']))
        ->toThrow(ValidationException::class);

    // Attempting 'fail' when hypervisor matches old specs succeeds
    $failed = $service->resolve($change, 'fail', 'Verified reverted on host', $env['admin']);
    expect($failed->status)->toBeInstanceOf(Failed::class)
        ->and($failed->error_code)->toBe('RECONCILED_FAILED');

    // Test 'complete' when hypervisor matches new specs
    $change2 = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['user']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => $change->old_resources,
        'new_resources' => $change->new_resources,
    ]);
    $env['driver']->setInstanceResourceSpec($env['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'container',
        network: new NetworkLimitsSpec(1000, 1000),
    ));

    $completed = $service->resolve($change2, 'complete', 'Verified applied on host', $env['admin']);
    expect($completed->status)->toBeInstanceOf(Completed::class)
        ->and($env['vm']->fresh()?->vps_plan_version_id)->toBe($env['toVersion']->id);

    // Test 'retry' transitions to Applying and dispatches ApplyVpsPlanChangeJob
    $change3 = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['user']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => $change->old_resources,
        'new_resources' => $change->new_resources,
    ]);

    $retried = $service->resolve($change3, 'retry', 'Retrying apply', $env['admin']);
    expect($retried->status)->toBeInstanceOf(Applying::class)
        ->and($retried->stage)->toBe('validating');
    Queue::assertPushed(ApplyVpsPlanChangeJob::class, fn (ApplyVpsPlanChangeJob $j): bool => $j->planChangeId === $change3->id);

    expect(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeResolved->value)->count())->toBe(3);
});

it('blocks VM lifecycle actions during an active plan change and marks it VM_DELETED on admin forced delete', function (): void {
    Queue::fake();

    $env = setupPlanChangeEnvironment();
    $planChangeService = app(VpsPlanChangeService::class);
    $lifecycleService = app(VmLifecycleService::class);

    $change = $planChangeService->request($env['vm'], $env['toVersion'], $env['user']);

    // Normal action (stop) is blocked with 409 ANOTHER_CHANGE_IN_PROGRESS
    try {
        $lifecycleService->handleActionRequest($env['vm'], 'stop', false, $env['user']);
        $this->fail('Expected HttpResponseException 409.');
    } catch (HttpResponseException $e) {
        $response = $e->getResponse();
        $body = json_decode((string) $response->getContent(), true);
        expect($response->getStatusCode())->toBe(409)
            ->and($body['code'] ?? null)->toBe('ANOTHER_CHANGE_IN_PROGRESS');
    }

    // Non-forced delete is also blocked
    try {
        $lifecycleService->handleActionRequest($env['vm'], 'delete', false, $env['admin']);
        $this->fail('Expected HttpResponseException 409.');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(409);
    }

    // Admin forced delete proceeds and marks the active plan change Failed with error_code = VM_DELETED
    $lifecycleService->handleActionRequest($env['vm'], 'delete', true, $env['admin']);

    $freshChange = $change->fresh();
    expect($freshChange?->status)->toBeInstanceOf(Failed::class)
        ->and($freshChange?->error_code)->toBe('VM_DELETED');

    Queue::assertPushed(ExecuteVmActionJob::class, fn (ExecuteVmActionJob $job): bool => $job->action === 'delete' && $job->force === true);
});
