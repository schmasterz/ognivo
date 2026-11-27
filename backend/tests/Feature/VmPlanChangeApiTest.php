<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\Enums\IpAllocationStatus;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\ServiceToken;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Vps\VpsPlanChangeService;
use App\States\Host\Ok as HostOk;
use App\States\Host\Unreachable as HostUnreachable;
use App\States\User\Suspended as UserSuspended;
use App\States\Vm\Running as VmRunning;
use App\States\VpsPlan\Draft as PlanDraft;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Pending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

uses(RefreshDatabase::class);

afterEach(function (): void {
    HypervisorManager::resetFake();
});

/**
 * Create a baseline environment with admin, two customers, a ready host, IP pool,
 * starter/pro/incompatible plans, and a running container VM for customer A.
 *
 * @return array{
 *     admin: User,
 *     customerA: User,
 *     customerB: User,
 *     host: Host,
 *     pool: IpPool,
 *     starterPlan: VpsPlan,
 *     starterV1: VpsPlanVersion,
 *     starterV2: VpsPlanVersion,
 *     proPlan: VpsPlan,
 *     proV1: VpsPlanVersion,
 *     vmPlan: VpsPlan,
 *     vmPlanV1: VpsPlanVersion,
 *     vmA: VirtualMachine,
 *     vmB: VirtualMachine,
 *     driver: FakeHypervisorDriver,
 * }
 */
function setupPlanChangeApiFixture(): array
{
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $driver = new FakeHypervisorDriver;
    HypervisorManager::fake($driver);

    $admin = User::factory()->create([
        'email' => 'plan-admin@example.com',
        'status' => 'active',
    ]);
    $admin->assignRole('admin');

    $customerA = User::factory()->create([
        'email' => 'plan-alice@example.com',
        'status' => 'active',
    ]);
    $customerA->assignRole('customer');

    $customerB = User::factory()->create([
        'email' => 'plan-bob@example.com',
        'status' => 'active',
    ]);
    $customerB->assignRole('customer');

    foreach ([$customerA, $customerB] as $customer) {
        UserQuota::query()->create([
            'user_id' => $customer->id,
            'max_vms' => 10,
            'max_cpu_cores' => 16,
            'max_ram_mb' => 32768,
            'max_storage_gb' => 500,
            'max_ips' => 10,
            'max_snapshots' => 10,
        ]);
    }

    $host = Host::factory()->create([
        'driver' => 'fake',
        'is_active' => true,
        'status' => HostOk::class,
        'capacity_source' => 'discovered',
        'total_cpu_cores' => 64,
        'total_ram_mb' => 131072,
        'total_storage_gb' => 2000,
    ]);

    $pool = IpPool::factory()->create([
        'host_id' => $host->id,
        'active' => true,
        'network_mode' => 'routed',
    ]);

    $starterPlan = VpsPlan::factory()->published()->create([
        'name' => 'Starter',
        'slug' => 'starter',
        'sort_order' => 10,
    ]);
    $starterV1 = VpsPlanVersion::factory()->superseded()->create([
        'vps_plan_id' => $starterPlan->id,
        'version' => 1,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 15,
        'instance_type' => 'container',
        'net_download_mbps' => 250,
        'net_upload_mbps' => 250,
    ]);
    $starterV2 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $starterPlan->id,
        'version' => 2,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'instance_type' => 'container',
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);
    $starterPlan->update(['current_version_id' => $starterV2->id]);

    $proPlan = VpsPlan::factory()->published()->create([
        'name' => 'Pro',
        'slug' => 'pro',
        'sort_order' => 20,
    ]);
    $proV1 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $proPlan->id,
        'version' => 1,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'container',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);
    $proPlan->update(['current_version_id' => $proV1->id]);

    $vmPlan = VpsPlan::factory()->published()->create([
        'name' => 'KVM Pro',
        'slug' => 'kvm-pro',
        'sort_order' => 30,
    ]);
    $vmPlanV1 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $vmPlan->id,
        'version' => 1,
        'cpu_cores' => 4,
        'ram_mb' => 4096,
        'disk_gb' => 40,
        'instance_type' => 'virtual-machine',
        'net_download_mbps' => 1000,
        'net_upload_mbps' => 1000,
    ]);
    $vmPlan->update(['current_version_id' => $vmPlanV1->id]);

    $vmA = VirtualMachine::factory()->create([
        'user_id' => $customerA->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $starterV2->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    IpAllocation::factory()->create([
        'pool_id' => $pool->id,
        'virtual_machine_id' => $vmA->id,
        'user_id' => $customerA->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    $vmB = VirtualMachine::factory()->create([
        'user_id' => $customerB->id,
        'host_id' => $host->id,
        'vps_plan_version_id' => $starterV2->id,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    return [
        'admin' => $admin,
        'customerA' => $customerA,
        'customerB' => $customerB,
        'host' => $host,
        'pool' => $pool,
        'starterPlan' => $starterPlan->fresh(),
        'starterV1' => $starterV1->fresh(),
        'starterV2' => $starterV2->fresh(),
        'proPlan' => $proPlan->fresh(),
        'proV1' => $proV1->fresh(),
        'vmPlan' => $vmPlan->fresh(),
        'vmPlanV1' => $vmPlanV1->fresh(),
        'vmA' => $vmA->fresh(),
        'vmB' => $vmB->fresh(),
        'driver' => $driver,
    ];
}

/**
 * Perform a stateful SPA request using captured session and XSRF cookies.
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, mixed>  $data
 * @return array{response: TestResponse<Response>, cookies: array<string, string>, xsrf: string|null}
 */
function performStatefulPlanChangeRequest(
    TestCase $test,
    string $method,
    string $uri,
    array $cookies,
    ?string $xsrf,
    array $data = [],
): array {
    app('auth')->forgetGuards();

    $headers = ['Origin' => 'http://localhost'];
    if ($xsrf !== null) {
        $headers['X-XSRF-TOKEN'] = $xsrf;
    }

    $response = $test->withCredentials()
        ->withUnencryptedCookies($cookies)
        ->withHeaders($headers)
        ->json($method, $uri, $data);

    foreach ($response->headers->getCookies() as $cookie) {
        $value = $cookie->getValue();
        if ($value !== null) {
            $cookies[$cookie->getName()] = $value;
        }
    }

    $newXsrf = $response->getCookie('XSRF-TOKEN')?->getValue();
    if (is_string($newXsrf) && $newXsrf !== '') {
        $xsrf = $newXsrf;
    }

    return [
        'response' => $response,
        'cookies' => $cookies,
        'xsrf' => $xsrf,
    ];
}

it('requires authentication and active status across all plan-change endpoints', function (): void {
    $fx = setupPlanChangeApiFixture();
    $vm = $fx['vmA'];
    $change = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $vm->id,
        'user_id' => $fx['customerA']->id,
        'to_version_id' => $fx['proV1']->id,
    ]);

    $this->getJson("/api/v1/vms/{$vm->id}/plan-change/targets")->assertStatus(401);
    $this->postJson("/api/v1/vms/{$vm->id}/plan-change/preview", ['vps_plan_id' => $fx['proPlan']->id])->assertStatus(401);
    $this->postJson("/api/v1/vms/{$vm->id}/plan-change", ['vps_plan_id' => $fx['proPlan']->id])->assertStatus(401);
    $this->getJson("/api/v1/vms/{$vm->id}/plan-changes")->assertStatus(401);
    $this->getJson("/api/v1/vms/{$vm->id}/plan-changes/{$change->public_id}")->assertStatus(401);
    $this->postJson("/api/v1/vms/{$vm->id}/plan-changes/{$change->public_id}/cancel")->assertStatus(401);

    $suspended = User::factory()->create(['status' => UserSuspended::class]);
    $suspended->assignRole('customer');
    $suspendedVm = VirtualMachine::factory()->create([
        'user_id' => $suspended->id,
        'host_id' => $fx['host']->id,
    ]);

    $this->actingAs($suspended)
        ->getJson("/api/v1/vms/{$suspendedVm->id}/plan-change/targets")
        ->assertStatus(403);
});

it('lists evaluated plan-change targets for the VM owner', function (): void {
    $fx = setupPlanChangeApiFixture();

    // Draft plan must not appear in targets
    VpsPlan::factory()->create([
        'name' => 'Secret Draft',
        'slug' => 'secret-draft',
        'status' => PlanDraft::class,
    ]);

    $response = $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/targets")
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $items = collect($response->json('data'))->keyBy('slug');

    expect($items->get('starter')['selectable'])->toBeFalse()
        ->and($items->get('starter')['blocking_reasons'][0]['code'])->toBe('NO_CHANGE')
        ->and($items->get('pro')['selectable'])->toBeTrue()
        ->and($items->get('pro')['requires_shutdown'])->toBeFalse()
        ->and($items->get('pro')['warnings'])->toContain('DISK_GUEST_RESIZE_MAY_BE_REQUIRED')
        ->and($items->get('pro')['changes'])->toBe([
            'cpu' => 'increase',
            'ram' => 'increase',
            'disk' => 'increase',
            'net_download' => 'increase',
            'net_upload' => 'increase',
        ])
        ->and($items->get('pro')['blocking_reasons'])->toBe([])
        ->and($items->get('kvm-pro')['selectable'])->toBeFalse()
        ->and($items->get('kvm-pro')['blocking_reasons'][0]['code'])->toBe('INSTANCE_TYPE_MISMATCH');
});

it('previews a plan change without mutating state or dispatching jobs', function (): void {
    $fx = setupPlanChangeApiFixture();
    Queue::fake();

    $response = $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/preview", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertOk();

    $response->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.requires_shutdown', false)
        ->assertJsonPath('data.from.vps_plan_id', $fx['starterPlan']->id)
        ->assertJsonPath('data.from.vps_plan_version_id', $fx['starterV2']->id)
        ->assertJsonPath('data.from.cpu_cores', 2)
        ->assertJsonPath('data.to.vps_plan_id', $fx['proPlan']->id)
        ->assertJsonPath('data.to.vps_plan_version_id', $fx['proV1']->id)
        ->assertJsonPath('data.to.cpu_cores', 4)
        ->assertJsonPath('data.changes.cpu', 'increase')
        ->assertJsonPath('data.changes.ram', 'increase')
        ->assertJsonPath('data.changes.disk', 'increase')
        ->assertJsonPath('data.blocking_reasons', []);

    expect($response->json('data.warnings'))->toContain('DISK_GUEST_RESIZE_MAY_BE_REQUIRED')
        ->and(VpsPlanChange::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('rejects non-admin vps_plan_version_id overrides with 422 RESOURCE_OVERRIDE_NOT_ALLOWED', function (): void {
    $fx = setupPlanChangeApiFixture();

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/preview", [
            'vps_plan_id' => $fx['proPlan']->id,
            'vps_plan_version_id' => $fx['proV1']->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'RESOURCE_OVERRIDE_NOT_ALLOWED');

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_version_id' => $fx['proV1']->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'RESOURCE_OVERRIDE_NOT_ALLOWED');
});

it('submits a plan change returning 202 and replays Idempotency-Key responses', function (): void {
    Queue::fake();
    $fx = setupPlanChangeApiFixture();

    $idempotencyKey = 'plan-change-key-001';

    $first = $this->actingAs($fx['customerA'])
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(202);

    $publicId = $first->json('data.public_id');
    expect($publicId)->toBeString()->not->toBeEmpty();

    $first->assertJsonPath('data.virtual_machine_id', $fx['vmA']->id)
        ->assertJsonPath('data.status', Pending::$name)
        ->assertJsonPath('data.from_version_id', $fx['starterV2']->id)
        ->assertJsonPath('data.to_version_id', $fx['proV1']->id)
        ->assertJsonPath('data.from.plan_slug', 'starter')
        ->assertJsonPath('data.to.plan_slug', 'pro')
        ->assertJsonPath('data.old_resources.cpu_cores', 2)
        ->assertJsonPath('data.new_resources.cpu_cores', 4);

    // Ensure internal driver/hypervisor metadata is never exposed
    expect($first->json('data'))->not->toHaveKeys([
        'driver_name',
        'driver_operation_id',
        'authorization_ref',
        'apply_deadline',
    ]);

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, 1);
    expect(VpsPlanChange::query()->count())->toBe(1);

    $replay = $this->actingAs($fx['customerA'])
        ->withHeader('Idempotency-Key', $idempotencyKey)
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(202)
        ->assertHeader('X-Idempotent-Replay', 'true');

    expect($replay->json('data.public_id'))->toBe($publicId)
        ->and(VpsPlanChange::query()->count())->toBe(1);

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, 1);
});

it('maps blocking reasons to 422, active conflict to 409, and unreachable host to 503', function (): void {
    Queue::fake();
    $fx = setupPlanChangeApiFixture();

    // 1. Blocking reason (disk shrink / requires shutdown) -> 422 with message, code, errors, blocking_reasons
    $shrinkPlan = VpsPlan::factory()->published()->create(['slug' => 'micro']);
    $shrinkV1 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $shrinkPlan->id,
        'version' => 1,
        'cpu_cores' => 1,
        'ram_mb' => 1024,
        'disk_gb' => 10,
        'instance_type' => 'container',
    ]);
    $shrinkPlan->update(['current_version_id' => $shrinkV1->id]);

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $shrinkPlan->id,
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['message', 'code', 'errors', 'blocking_reasons'])
        ->assertJsonPath('code', 'DISK_SHRINK_NOT_SUPPORTED');

    // Also verify driver requires_shutdown maps to 422
    $fx['driver']->setAssessmentVerdict($fx['vmA'], ResourceKind::Cpu, ResourceUpdateVerdict::RequiresStop, 'CPU_HOTPLUG_DISABLED');

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'REQUIRES_SHUTDOWN_NOT_SUPPORTED');

    $fx['driver']->setAssessmentOverrides($fx['vmA'], []);

    // 2. Host unreachable -> 503
    $fx['host']->update(['status' => HostUnreachable::class]);

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(503)
        ->assertJsonPath('code', 'HOST_UNREACHABLE')
        ->assertJsonStructure(['message', 'code', 'errors', 'blocking_reasons']);

    $fx['host']->update(['status' => HostOk::class]);

    // 3. ANOTHER_CHANGE_IN_PROGRESS -> 409
    VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'to_version_id' => $fx['proV1']->id,
        'status' => Pending::class,
    ]);

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'ANOTHER_CHANGE_IN_PROGRESS');
});

it('supports legacy VMs with from_version_id = NULL and assigns target version on completion', function (): void {
    Queue::fake();
    $fx = setupPlanChangeApiFixture();

    $legacyVm = VirtualMachine::factory()->create([
        'user_id' => $fx['customerA']->id,
        'host_id' => $fx['host']->id,
        'vps_plan_version_id' => null,
        'instance_type' => 'container',
        'status' => VmRunning::class,
        'cpu_cores' => 2,
        'ram_mb' => 2048,
        'disk_gb' => 20,
        'net_download_mbps' => 500,
        'net_upload_mbps' => 500,
    ]);

    IpAllocation::factory()->create([
        'pool_id' => $fx['pool']->id,
        'virtual_machine_id' => $legacyVm->id,
        'user_id' => $fx['customerA']->id,
        'status' => IpAllocationStatus::Allocated,
    ]);

    $preview = $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$legacyVm->id}/plan-change/preview", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertOk();

    $preview->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.from.vps_plan_id', null)
        ->assertJsonPath('data.from.vps_plan_version_id', null)
        ->assertJsonPath('data.from.cpu_cores', 2)
        ->assertJsonPath('data.to.vps_plan_version_id', $fx['proV1']->id);

    $store = $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$legacyVm->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(202);

    $store->assertJsonPath('data.from_version_id', null)
        ->assertJsonPath('data.from', null)
        ->assertJsonPath('data.to_version_id', $fx['proV1']->id)
        ->assertJsonPath('data.old_resources.cpu_cores', 2)
        ->assertJsonPath('data.new_resources.cpu_cores', 4);

    /** @var VpsPlanChange $planChange */
    $planChange = VpsPlanChange::query()->where('public_id', $store->json('data.public_id'))->firstOrFail();
    expect($planChange->from_version_id)->toBeNull();

    $service = app(VpsPlanChangeService::class);
    $started = $service->startApplying($planChange);
    expect($started)->not->toBeNull();
    $service->commitCompleted($started);

    expect($legacyVm->fresh()?->vps_plan_version_id)->toBe($fx['proV1']->id);
});

it('lists paginated history, shows a single sanitized plan change, and cancels pending changes', function (): void {
    $fx = setupPlanChangeApiFixture();

    $completedOlder = VpsPlanChange::factory()->completed()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'from_version_id' => $fx['starterV1']->id,
        'to_version_id' => $fx['starterV2']->id,
        'created_at' => now()->subMinutes(10),
    ]);

    $failedWithSensitiveError = VpsPlanChange::factory()->failed()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'from_version_id' => $fx['starterV2']->id,
        'to_version_id' => $fx['proV1']->id,
        'driver_name' => 'incus',
        'driver_operation_id' => 'op-secret-999',
        'authorization_ref' => 'auth-secret-ref',
        'error_code' => 'HYPERVISOR_ERROR',
        'error_message' => 'Connection failed to https://10.0.0.5:8443/1.0/instances/vm with Bearer secret_token_123',
        'created_at' => now()->subMinutes(5),
    ]);

    $pendingNewest = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'from_version_id' => $fx['starterV2']->id,
        'to_version_id' => $fx['proV1']->id,
        'status' => Pending::class,
        'created_at' => now(),
    ]);

    // Unrelated VM change must not appear in vmA index or show
    $otherChange = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmB']->id,
        'user_id' => $fx['customerB']->id,
        'to_version_id' => $fx['proV1']->id,
    ]);

    $index = $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);

    expect($index->json('data.0.public_id'))->toBe($pendingNewest->public_id)
        ->and($index->json('data.1.public_id'))->toBe($failedWithSensitiveError->public_id);

    $show = $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$failedWithSensitiveError->public_id}")
        ->assertOk()
        ->assertJsonPath('data.public_id', $failedWithSensitiveError->public_id)
        ->assertJsonPath('data.error_code', 'HYPERVISOR_ERROR');

    $errorMessage = (string) $show->json('data.error_message');
    expect($errorMessage)
        ->not->toContain('10.0.0.5:8443')
        ->not->toContain('secret_token_123')
        ->and($errorMessage)->toContain('[redacted]')
        ->and($show->json('data'))->not->toHaveKeys([
            'driver_name',
            'driver_operation_id',
            'authorization_ref',
        ]);

    // Show belonging to a different VM -> 404
    $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$otherChange->public_id}")
        ->assertNotFound();

    // Cancel pending -> 200 cancelled
    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$pendingNewest->public_id}/cancel")
        ->assertOk()
        ->assertJsonPath('data.public_id', $pendingNewest->public_id)
        ->assertJsonPath('data.status', Cancelled::$name);

    // Cancel applying -> 409
    $applying = VpsPlanChange::factory()->applying()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'to_version_id' => $fx['proV1']->id,
    ]);

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$applying->public_id}/cancel")
        ->assertStatus(409);

    // Cancel belonging to a different VM -> 404
    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$otherChange->public_id}/cancel")
        ->assertNotFound();

    expect($completedOlder->fresh()?->status->getValue())->toBe('completed');
});

it('returns 404 when a non-owner attempts to access another user VM plan-change endpoints', function (): void {
    $fx = setupPlanChangeApiFixture();

    $changeB = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmB']->id,
        'user_id' => $fx['customerB']->id,
        'to_version_id' => $fx['proV1']->id,
    ]);

    $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmB']->id}/plan-change/targets")
        ->assertNotFound();

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmB']->id}/plan-change/preview", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertNotFound();

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmB']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertNotFound();

    $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmB']->id}/plan-changes")
        ->assertNotFound();

    $this->actingAs($fx['customerA'])
        ->getJson("/api/v1/vms/{$fx['vmB']->id}/plan-changes/{$changeB->public_id}")
        ->assertNotFound();

    $this->actingAs($fx['customerA'])
        ->postJson("/api/v1/vms/{$fx['vmB']->id}/plan-changes/{$changeB->public_id}/cancel")
        ->assertNotFound();
});

it('allows administrators to access tenant VMs and target a specific vps_plan_version_id', function (): void {
    Queue::fake();
    $fx = setupPlanChangeApiFixture();

    // Admin previews a superseded version (starterV1 -> disk_gb 15 vs vmA disk_gb 20; test proV1 or a new superseded version)
    $proV2 = VpsPlanVersion::factory()->published()->create([
        'vps_plan_id' => $fx['proPlan']->id,
        'version' => 2,
        'cpu_cores' => 8,
        'ram_mb' => 8192,
        'disk_gb' => 60,
        'instance_type' => 'container',
    ]);
    $fx['proPlan']->update(['current_version_id' => $proV2->id]);

    // Mark proV1 superseded so non-admin cannot use it, but admin can explicitly target proV1 via vps_plan_version_id
    DB::table('vps_plan_versions')
        ->where('id', $fx['proV1']->id)
        ->update(['status' => 'superseded']);

    $this->actingAs($fx['admin'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/preview", [
            'vps_plan_version_id' => $fx['proV1']->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.can_submit', true)
        ->assertJsonPath('data.to.vps_plan_version_id', $fx['proV1']->id)
        ->assertJsonPath('data.to.cpu_cores', 4);

    // Mismatched vps_plan_id + vps_plan_version_id fails validation
    $this->actingAs($fx['admin'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['starterPlan']->id,
            'vps_plan_version_id' => $fx['proV1']->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['vps_plan_version_id']);

    $store = $this->actingAs($fx['admin'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_version_id' => $fx['proV1']->id,
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.to_version_id', $fx['proV1']->id);

    $publicId = $store->json('data.public_id');

    $this->actingAs($fx['admin'])
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$publicId}")
        ->assertOk();

    $this->actingAs($fx['admin'])
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$publicId}/cancel")
        ->assertOk()
        ->assertJsonPath('data.status', Cancelled::$name);
});

it('forbids mutating plan-change actions while impersonating and records VpsPlanChangeDenied audit log', function (): void {
    $fx = setupPlanChangeApiFixture();
    Queue::fake();

    $pending = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'to_version_id' => $fx['proV1']->id,
        'status' => Pending::class,
    ]);

    $cookies = [];
    $xsrf = null;

    $csrfResponse = $this->withHeader('Origin', 'http://localhost')
        ->get('/sanctum/csrf-cookie');
    $csrfResponse->assertNoContent();
    foreach ($csrfResponse->headers->getCookies() as $cookie) {
        $value = $cookie->getValue();
        if ($value !== null) {
            $cookies[$cookie->getName()] = $value;
        }
    }
    $xsrf = $csrfResponse->getCookie('XSRF-TOKEN')?->getValue();

    // Login as admin and start impersonating customerA
    $login = performStatefulPlanChangeRequest($this, 'POST', '/api/v1/login', $cookies, $xsrf, [
        'email' => $fx['admin']->email,
        'password' => 'password',
    ]);
    $login['response']->assertOk();
    $cookies = $login['cookies'];
    $xsrf = $login['xsrf'];

    $imp = performStatefulPlanChangeRequest(
        $this,
        'POST',
        "/api/v1/admin/users/{$fx['customerA']->id}/impersonate",
        $cookies,
        $xsrf
    );
    $imp['response']->assertOk();
    $cookies = $imp['cookies'];
    $xsrf = $imp['xsrf'];

    // Read-only endpoints remain allowed during impersonation
    $targetsRes = performStatefulPlanChangeRequest(
        $this,
        'GET',
        "/api/v1/vms/{$fx['vmA']->id}/plan-change/targets",
        $cookies,
        $xsrf
    );
    $targetsRes['response']->assertOk();
    $cookies = $targetsRes['cookies'];
    $xsrf = $targetsRes['xsrf'];

    $previewRes = performStatefulPlanChangeRequest(
        $this,
        'POST',
        "/api/v1/vms/{$fx['vmA']->id}/plan-change/preview",
        $cookies,
        $xsrf,
        ['vps_plan_id' => $fx['proPlan']->id]
    );
    $previewRes['response']->assertOk();
    $cookies = $previewRes['cookies'];
    $xsrf = $previewRes['xsrf'];

    // Mutating store is blocked with 403 and audited
    $storeRes = performStatefulPlanChangeRequest(
        $this,
        'POST',
        "/api/v1/vms/{$fx['vmA']->id}/plan-change",
        $cookies,
        $xsrf,
        ['vps_plan_id' => $fx['proPlan']->id]
    );
    $storeRes['response']->assertStatus(403);
    $cookies = $storeRes['cookies'];
    $xsrf = $storeRes['xsrf'];

    /** @var AuditLog|null $storeDeniedLog */
    $storeDeniedLog = AuditLog::query()
        ->where('action', AuditAction::VpsPlanChangeDenied->value)
        ->where('outcome', AuditOutcome::Denied->value)
        ->latest('id')
        ->first();

    expect($storeDeniedLog)->not->toBeNull()
        ->and($storeDeniedLog?->impersonator_id)->toBe($fx['admin']->id)
        ->and($storeDeniedLog?->tenant_user_id)->toBe($fx['customerA']->id)
        ->and($storeDeniedLog?->context['action'] ?? null)->toBe('vps.plan_change.request');

    // Mutating cancel is blocked with 403 and audited
    $cancelRes = performStatefulPlanChangeRequest(
        $this,
        'POST',
        "/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$pending->public_id}/cancel",
        $cookies,
        $xsrf
    );
    $cancelRes['response']->assertStatus(403);

    /** @var AuditLog|null $cancelDeniedLog */
    $cancelDeniedLog = AuditLog::query()
        ->where('action', AuditAction::VpsPlanChangeDenied->value)
        ->where('outcome', AuditOutcome::Denied->value)
        ->latest('id')
        ->first();

    expect($cancelDeniedLog)->not->toBeNull()
        ->and($cancelDeniedLog?->impersonator_id)->toBe($fx['admin']->id)
        ->and($cancelDeniedLog?->tenant_user_id)->toBe($fx['customerA']->id)
        ->and($cancelDeniedLog?->context['action'] ?? null)->toBe('vps.plan_change.cancel');

    expect($pending->fresh()?->status->getValue())->toBe(Pending::$name);
    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);
});

it('rejects service-token-authenticated requests with 403 on all plan-change endpoints', function (): void {
    $fx = setupPlanChangeApiFixture();
    $generated = ServiceToken::generate('automation-ci', ['*']);
    $change = VpsPlanChange::factory()->create([
        'virtual_machine_id' => $fx['vmA']->id,
        'user_id' => $fx['customerA']->id,
        'to_version_id' => $fx['proV1']->id,
        'status' => Pending::class,
    ]);

    $headers = ['Authorization' => 'Bearer '.$generated['plain_text_token']];

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/targets")
        ->assertStatus(403);

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change/preview", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(403);

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-change", [
            'vps_plan_id' => $fx['proPlan']->id,
        ])
        ->assertStatus(403);

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes")
        ->assertStatus(403);

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->getJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$change->public_id}")
        ->assertStatus(403);

    $this->actingAs($fx['customerA'])
        ->withHeaders($headers)
        ->postJson("/api/v1/vms/{$fx['vmA']->id}/plan-changes/{$change->public_id}/cancel")
        ->assertStatus(403);
});
