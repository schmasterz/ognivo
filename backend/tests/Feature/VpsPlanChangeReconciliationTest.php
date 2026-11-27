<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use App\Enums\IpAllocationStatus;
use App\Events\Vps\VpsPlanChangeCancelled;
use App\Events\Vps\VpsPlanChangeCompleted;
use App\Events\Vps\VpsPlanChangeReconciliationRequired;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Jobs\ReconcileVpsPlanChangeJob;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\VmReconciliationService;
use App\Services\Vps\VpsPlanChangeService;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Running as VmRunning;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanChange\Applying;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Completed;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\States\VpsPlanChange\ReconciliationRequired;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

afterEach(function (): void {
    HypervisorManager::resetFake();
});

/**
 * @return array{
 *     admin: User,
 *     customer: User,
 *     host: Host,
 *     pool: IpPool,
 *     driver: FakeHypervisorDriver,
 *     plan: VpsPlan,
 *     fromVersion: VpsPlanVersion,
 *     toVersion: VpsPlanVersion,
 *     vm: VirtualMachine,
 * }
 */
function setupReconciliationEnv(string $poolMode = 'routed', array $vmOverrides = []): array
{
    config(['app.allow_fake_driver' => true]);

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $admin = User::factory()->create(['status' => 'active']);
    $admin->assignRole('admin');

    $projectName = 'tenant-reconcile-'.uniqid();

    $customer = User::factory()->create([
        'status' => 'active',
        'project_name' => $projectName,
    ]);
    $customer->assignRole('customer');

    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => HostOk::class,
        'total_cpu_cores' => 32,
        'total_ram_mb' => 65536,
        'total_storage_gb' => 1000,
    ]);

    $pool = IpPool::factory()->create([
        'host_id' => $host->id,
        'network_mode' => $poolMode,
        'active' => true,
    ]);

    $plan = VpsPlan::factory()->create(['status' => PlanPublished::class]);

    $fromVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $plan->id,
        'version' => 1,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    $toVersion = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $plan->id,
        'version' => 2,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);

    $plan->forceFill(['current_version_id' => $toVersion->id])->saveQuietly();

    $vm = VirtualMachine::factory()->create(array_merge([
        'user_id' => $customer->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $fromVersion->id,
        'name' => 'reconcile-vm-'.uniqid(),
        'incus_project' => $projectName,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ], $vmOverrides));

    $rawAddress = inet_pton('10.80.0.10');
    IpAllocation::create([
        'pool_id' => $pool->id,
        'address' => $rawAddress !== false ? $rawAddress : '10.80.0.10',
        'ip_string' => '10.80.0.10',
        'status' => IpAllocationStatus::Allocated->value,
        'user_id' => $customer->id,
        'virtual_machine_id' => $vm->id,
        'allocated_at' => now(),
    ]);

    $driver = new FakeHypervisorDriver;
    $driver->projects = [$projectName];
    $driver->instances = [
        [
            'name' => $vm->name,
            'project' => $vm->incus_project,
            'status' => 'Running',
        ],
    ];
    $driver->statuses[$vm->name] = 'Running';
    $driver->setInstanceResourceSpec($vm, new InstanceResourceSpec(
        cpuCores: (int) $vm->cpu_cores,
        ramMb: (int) $vm->ram_mb,
        diskGb: (int) $vm->disk_gb,
        instanceType: $vm->instance_type,
        network: ($vm->net_download_mbps !== null || $vm->net_upload_mbps !== null)
            ? new NetworkLimitsSpec($vm->net_download_mbps, $vm->net_upload_mbps)
            : null,
    ));

    HypervisorManager::fake($driver);

    return [
        'admin' => $admin,
        'customer' => $customer,
        'host' => $host,
        'pool' => $pool,
        'driver' => $driver,
        'plan' => $plan,
        'fromVersion' => $fromVersion,
        'toVersion' => $toVersion,
        'vm' => $vm,
    ];
}

it('schedules vps:reconcile-plan-changes every five minutes without overlapping', function (): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())->first(
        fn (ScheduledEvent $event): bool => is_string($event->command)
            && str_contains($event->command, 'vps:reconcile-plan-changes')
    );

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('*/5 * * * *')
        ->and($event?->withoutOverlapping)->toBeTrue();
});

it('sweeps stale pending, applying, and awaiting_authorization plan changes while leaving fresh ones untouched', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeCancelled::class]);

    $env = setupReconciliationEnv();

    $vmFreshPending = VirtualMachine::factory()->create([
        'user_id' => $env['customer']->id,
        'host_id' => $env['host']->id,
        'name' => 'vm-fresh-pending',
        'incus_project' => 'tenant-reconcile-1',
    ]);
    $vmStaleApplying = VirtualMachine::factory()->create([
        'user_id' => $env['customer']->id,
        'host_id' => $env['host']->id,
        'name' => 'vm-stale-applying',
        'incus_project' => 'tenant-reconcile-1',
    ]);
    $vmFreshApplying = VirtualMachine::factory()->create([
        'user_id' => $env['customer']->id,
        'host_id' => $env['host']->id,
        'name' => 'vm-fresh-applying',
        'incus_project' => 'tenant-reconcile-1',
    ]);
    $vmExpiredAuth = VirtualMachine::factory()->create([
        'user_id' => $env['customer']->id,
        'host_id' => $env['host']->id,
        'name' => 'vm-expired-auth',
        'incus_project' => 'tenant-reconcile-1',
    ]);
    $vmFreshAuth = VirtualMachine::factory()->create([
        'user_id' => $env['customer']->id,
        'host_id' => $env['host']->id,
        'name' => 'vm-fresh-auth',
        'incus_project' => 'tenant-reconcile-1',
    ]);

    $stalePending = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'requested_at' => now()->subMinutes(20),
    ]);
    DB::table('vps_plan_changes')->where('id', $stalePending->id)->update([
        'created_at' => now()->subMinutes(20),
        'updated_at' => now()->subMinutes(20),
    ]);

    $freshPending = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $vmFreshPending->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'requested_at' => now()->subMinutes(5),
    ]);

    $staleApplying = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $vmStaleApplying->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'started_at' => now()->subMinutes(35),
    ]);
    DB::table('vps_plan_changes')->where('id', $staleApplying->id)->update([
        'updated_at' => now()->subMinutes(35),
    ]);

    $freshApplying = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $vmFreshApplying->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'started_at' => now()->subMinutes(10),
    ]);

    $expiredAuth = VpsPlanChange::factory()->awaitingAuthorization()->create([
        'virtual_machine_id' => $vmExpiredAuth->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'requested_at' => now()->subMinutes(65),
    ]);
    DB::table('vps_plan_changes')->where('id', $expiredAuth->id)->update([
        'updated_at' => now()->subMinutes(65),
    ]);

    $freshAuth = VpsPlanChange::factory()->awaitingAuthorization()->create([
        'virtual_machine_id' => $vmFreshAuth->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'requested_at' => now()->subMinutes(15),
    ]);

    $this->artisan('vps:reconcile-plan-changes')
        ->expectsOutputToContain('Pending re-dispatched: 1, Applying reconciled: 1, Authorizations timed out: 1.')
        ->assertSuccessful();

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, 1);
    Queue::assertPushed(
        ApplyVpsPlanChangeJob::class,
        fn (ApplyVpsPlanChangeJob $job): bool => $job->planChangeId === $stalePending->id
    );

    Queue::assertPushed(ReconcileVpsPlanChangeJob::class, 1);
    Queue::assertPushed(
        ReconcileVpsPlanChangeJob::class,
        fn (ReconcileVpsPlanChangeJob $job): bool => $job->planChangeId === $staleApplying->id
    );

    expect($freshPending->fresh()?->status)->toBeInstanceOf(Pending::class)
        ->and($freshApplying->fresh()?->status)->toBeInstanceOf(Applying::class)
        ->and($freshAuth->fresh()?->status)->toBeInstanceOf(AwaitingAuthorization::class);

    $cancelledAuth = $expiredAuth->fresh();
    expect($cancelledAuth?->status)->toBeInstanceOf(Cancelled::class)
        ->and($cancelledAuth?->error_code)->toBe('AUTHORIZATION_TIMEOUT')
        ->and($cancelledAuth?->stage)->toBeNull();

    Event::assertDispatched(
        VpsPlanChangeCancelled::class,
        fn (VpsPlanChangeCancelled $event): bool => $event->planChangeId === $expiredAuth->id
    );

    expect(DB::table('audit_logs')
        ->where('subject_id', (string) $stalePending->id)
        ->where('action', AuditAction::VpsPlanChangeRequested->value)
        ->count())->toBe(1);

    expect(DB::table('audit_logs')
        ->where('subject_id', (string) $expiredAuth->id)
        ->where('action', AuditAction::VpsPlanChangeCancelled->value)
        ->count())->toBe(1);
});

it('reconciles Applying plan changes in ReconcileVpsPlanChangeJob across new, old, partial, unreachable, and non-applying outcomes', function (): void {
    Event::fake([VpsPlanChangeCompleted::class, VpsPlanChangeReconciliationRequired::class]);

    $service = app(VpsPlanChangeService::class);
    $manager = app(HypervisorManager::class);

    $oldResources = [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ];
    $newResources = [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ];

    // 1. Non-Applying exits as no-op
    $envNoop = setupReconciliationEnv();
    $pending = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $envNoop['vm']->id,
        'user_id' => $envNoop['customer']->id,
        'from_version_id' => $envNoop['fromVersion']->id,
        'to_version_id' => $envNoop['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    (new ReconcileVpsPlanChangeJob($pending->id, $envNoop['vm']->id))->handle($service, $manager);
    expect($pending->fresh()?->status)->toBeInstanceOf(Pending::class);

    // 2. Observed == new -> commitCompleted
    HypervisorManager::resetFake();
    $envNew = setupReconciliationEnv();
    $changeNew = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $envNew['vm']->id,
        'user_id' => $envNew['customer']->id,
        'from_version_id' => $envNew['fromVersion']->id,
        'to_version_id' => $envNew['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    $envNew['driver']->setInstanceResourceSpec($envNew['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'container',
        network: new NetworkLimitsSpec(1000, 1000),
    ));
    (new ReconcileVpsPlanChangeJob($changeNew->id, $envNew['vm']->id))->handle($service, $manager);
    expect($changeNew->fresh()?->status)->toBeInstanceOf(Completed::class)
        ->and($envNew['vm']->fresh()?->vps_plan_version_id)->toBe($envNew['toVersion']->id)
        ->and($envNew['vm']->fresh()?->cpu_cores)->toBe(4)
        ->and($envNew['vm']->fresh()?->ram_mb)->toBe(4096);
    Event::assertDispatched(VpsPlanChangeCompleted::class, fn (VpsPlanChangeCompleted $e): bool => $e->planChangeId === $changeNew->id);

    // 3. Observed == old -> markFailed(NOT_APPLIED)
    HypervisorManager::resetFake();
    $envOld = setupReconciliationEnv();
    $changeOld = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $envOld['vm']->id,
        'user_id' => $envOld['customer']->id,
        'from_version_id' => $envOld['fromVersion']->id,
        'to_version_id' => $envOld['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    (new ReconcileVpsPlanChangeJob($changeOld->id, $envOld['vm']->id))->handle($service, $manager);
    $failed = $changeOld->fresh();
    expect($failed?->status)->toBeInstanceOf(Failed::class)
        ->and($failed?->error_code)->toBe('NOT_APPLIED')
        ->and($failed?->error_message)->toBe('Hypervisor state matches previous plan resources.')
        ->and($envOld['vm']->fresh()?->vps_plan_version_id)->toBe($envOld['fromVersion']->id);

    // 4. Observed == partial -> ReconciliationRequired + DriftReport(resource_mismatch) + event
    HypervisorManager::resetFake();
    $envPartial = setupReconciliationEnv();
    $changePartial = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $envPartial['vm']->id,
        'user_id' => $envPartial['customer']->id,
        'from_version_id' => $envPartial['fromVersion']->id,
        'to_version_id' => $envPartial['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    $envPartial['driver']->setInstanceResourceSpec($envPartial['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(500, 500),
    ));
    (new ReconcileVpsPlanChangeJob($changePartial->id, $envPartial['vm']->id))->handle($service, $manager);
    expect($changePartial->fresh()?->status)->toBeInstanceOf(ReconciliationRequired::class);
    Event::assertDispatched(
        VpsPlanChangeReconciliationRequired::class,
        fn (VpsPlanChangeReconciliationRequired $e): bool => $e->planChangeId === $changePartial->id
    );
    $drift = DriftReport::query()
        ->where('virtual_machine_id', $envPartial['vm']->id)
        ->where('kind', 'resource_mismatch')
        ->whereNull('resolved_at')
        ->first();
    expect($drift)->not->toBeNull();

    // 5. Unreachable host -> logs warning and exits gracefully without crashing
    HypervisorManager::resetFake();
    Log::spy();
    $envUnreachable = setupReconciliationEnv();
    $changeUnreachable = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $envUnreachable['vm']->id,
        'user_id' => $envUnreachable['customer']->id,
        'from_version_id' => $envUnreachable['fromVersion']->id,
        'to_version_id' => $envUnreachable['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    $envUnreachable['driver']->setHostUnreachable($envUnreachable['host'], true);
    (new ReconcileVpsPlanChangeJob($changeUnreachable->id, $envUnreachable['vm']->id))->handle($service, $manager);
    expect($changeUnreachable->fresh()?->status)->toBeInstanceOf(Applying::class);
    Log::shouldHaveReceived('warning')->once();
});

it('detects and resolves resource_mismatch drift in VmReconciliationService while skipping VMs with active plan changes and manual-pool network limits', function (): void {
    // 1. Mismatch on non-manual pool creates resource_mismatch drift, and matching resolves it
    $env = setupReconciliationEnv('routed');
    $env['driver']->setInstanceResourceSpec($env['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(500, 500),
    ));

    $reconcileService = app(VmReconciliationService::class);
    $stats = $reconcileService->reconcile($env['host']);

    expect($stats['drifts_detected'])->toBe(1)
        ->and(DriftReport::query()
            ->where('host_id', $env['host']->id)
            ->where('virtual_machine_id', $env['vm']->id)
            ->where('kind', 'resource_mismatch')
            ->whereNull('resolved_at')
            ->exists())->toBeTrue();

    // Re-running while mismatched updates existing open drift without duplicating
    $reconcileService->reconcile($env['host']);
    expect(DriftReport::query()
        ->where('virtual_machine_id', $env['vm']->id)
        ->where('kind', 'resource_mismatch')
        ->whereNull('resolved_at')
        ->count())->toBe(1);

    // Fix hypervisor state to match DB -> drift is auto-resolved
    $env['driver']->setInstanceResourceSpec($env['vm'], new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(500, 500),
    ));
    $resolvedStats = $reconcileService->reconcile($env['host']);
    expect($resolvedStats['drifts_resolved'])->toBe(1)
        ->and(DriftReport::query()
            ->where('virtual_machine_id', $env['vm']->id)
            ->where('kind', 'resource_mismatch')
            ->whereNull('resolved_at')
            ->exists())->toBeFalse();

    // 2. Manual pool skips network limit comparison
    HypervisorManager::resetFake();
    $envManual = setupReconciliationEnv('manual', [
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);
    $envManual['driver']->setInstanceResourceSpec($envManual['vm'], new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: null,
    ));
    $manualStats = app(VmReconciliationService::class)->reconcile($envManual['host']);
    expect($manualStats['drifts_detected'])->toBe(0);

    // 3. VM with an active plan change is skipped (no new drift, open drift preserved)
    HypervisorManager::resetFake();
    $envActive = setupReconciliationEnv('routed');
    $openDrift = DriftReport::create([
        'host_id' => $envActive['host']->id,
        'virtual_machine_id' => $envActive['vm']->id,
        'instance_name' => $envActive['vm']->name,
        'incus_project' => $envActive['vm']->incus_project,
        'kind' => 'resource_mismatch',
        'resolved_at' => null,
    ]);
    VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $envActive['vm']->id,
        'user_id' => $envActive['customer']->id,
        'from_version_id' => $envActive['fromVersion']->id,
        'to_version_id' => $envActive['toVersion']->id,
    ]);

    $activeStats = app(VmReconciliationService::class)->reconcile($envActive['host']);
    expect($activeStats['drifts_detected'])->toBe(0)
        ->and($activeStats['drifts_resolved'])->toBe(0)
        ->and($openDrift->fresh()?->resolved_at)->toBeNull();
});

it('enforces authorization and handles list, show, and resolve endpoints in AdminVpsPlanChangeController', function (): void {
    Queue::fake();

    $env = setupReconciliationEnv();

    $oldResources = [
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ];
    $newResources = [
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ];

    $reconcileChange = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);

    DriftReport::create([
        'host_id' => $env['host']->id,
        'virtual_machine_id' => $env['vm']->id,
        'instance_name' => $env['vm']->name,
        'incus_project' => $env['vm']->incus_project,
        'kind' => 'resource_mismatch',
        'resolved_at' => null,
    ]);

    // Unauthenticated & non-admin authorization checks
    $this->getJson('/api/v1/admin/vps/plan-changes')->assertStatus(401);
    $this->getJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}")->assertStatus(401);
    $this->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", ['action' => 'retry'])->assertStatus(401);

    $this->actingAs($env['customer'])->getJson('/api/v1/admin/vps/plan-changes')->assertStatus(403);
    $this->actingAs($env['customer'])->getJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}")->assertStatus(403);
    $this->actingAs($env['customer'])->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", ['action' => 'retry'])->assertStatus(403);

    // 404 for unknown public_id
    $this->actingAs($env['admin'])
        ->getJson('/api/v1/admin/vps/plan-changes/01nonexistentulid0000000000')
        ->assertStatus(404);

    // Index with filters & show
    $this->actingAs($env['admin'])
        ->getJson("/api/v1/admin/vps/plan-changes?status=reconciliation_required&user_id={$env['customer']->id}&vm_id={$env['vm']->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $reconcileChange->public_id)
        ->assertJsonPath('data.0.status', 'reconciliation_required')
        ->assertJsonPath('meta.total', 1);

    $this->actingAs($env['admin'])
        ->getJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}")
        ->assertOk()
        ->assertJsonPath('data.public_id', $reconcileChange->public_id)
        ->assertJsonPath('data.virtual_machine.id', $env['vm']->id);

    // 422 when action=complete but hypervisor still has old resources
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", [
            'action' => 'complete',
            'note' => 'Mismatch check',
        ])
        ->assertStatus(422);

    // 503 when host is unreachable during resolve
    $env['driver']->setHostUnreachable($env['host'], true);
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", [
            'action' => 'fail',
            'note' => 'Host offline',
        ])
        ->assertStatus(503)
        ->assertJsonPath('code', 'HOST_UNREACHABLE');
    $env['driver']->setHostUnreachable($env['host'], false);

    // 200 action=complete when observed == new
    $env['driver']->setInstanceResourceSpec($env['vm'], new InstanceResourceSpec(
        cpuCores: 4,
        ramMb: 4096,
        diskGb: 40,
        instanceType: 'container',
        network: new NetworkLimitsSpec(1000, 1000),
    ));
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", [
            'action' => 'complete',
            'note' => 'Confirmed target spec on host',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'completed');

    expect($env['vm']->fresh()?->vps_plan_version_id)->toBe($env['toVersion']->id)
        ->and(DriftReport::query()->where('virtual_machine_id', $env['vm']->id)->where('kind', 'resource_mismatch')->whereNull('resolved_at')->exists())->toBeFalse();

    // 409 when resolving an already completed change
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$reconcileChange->public_id}/resolve", [
            'action' => 'retry',
        ])
        ->assertStatus(409);

    // 200 action=fail when observed == old
    $env['driver']->setInstanceResourceSpec($env['vm'], new InstanceResourceSpec(
        cpuCores: 2,
        ramMb: 2048,
        diskGb: 20,
        instanceType: 'container',
        network: new NetworkLimitsSpec(500, 500),
    ));
    $changeToFail = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$changeToFail->public_id}/resolve", [
            'action' => 'fail',
            'note' => 'Verified reverted on host',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.error_code', 'RECONCILED_FAILED');

    // 200 action=retry transitions to applying and dispatches ApplyVpsPlanChangeJob
    $changeToRetry = VpsPlanChange::factory()->reconciliationRequired()->create([
        'virtual_machine_id' => $env['vm']->id,
        'user_id' => $env['customer']->id,
        'from_version_id' => $env['fromVersion']->id,
        'to_version_id' => $env['toVersion']->id,
        'old_resources' => $oldResources,
        'new_resources' => $newResources,
    ]);
    $this->actingAs($env['admin'])
        ->postJson("/api/v1/admin/vps/plan-changes/{$changeToRetry->public_id}/resolve", [
            'action' => 'retry',
            'note' => 'Re-running apply job',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'applying')
        ->assertJsonPath('data.stage', 'validating');

    Queue::assertPushed(
        ApplyVpsPlanChangeJob::class,
        fn (ApplyVpsPlanChangeJob $job): bool => $job->planChangeId === $changeToRetry->id
    );
});
