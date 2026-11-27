<?php

declare(strict_types=1);

use App\Audit\AuditAction;
use App\Contracts\Data\VpsPlanChangeAuthorizationRequest;
use App\Contracts\Data\VpsPlanChangeAuthorizationResult;
use App\Contracts\Data\VpsProvisioningAuthorizationRequest;
use App\Contracts\Data\VpsProvisioningAuthorizationResult;
use App\Contracts\VpsPlanChangeAuthorizerInterface;
use App\Contracts\VpsProvisioningAuthorizerInterface;
use App\Enums\IpAllocationStatus;
use App\Enums\Vps\VpsAuthorizationStatus;
use App\Events\Vps\VpsPlanChangeFailed;
use App\Jobs\ApplyVpsPlanChangeJob;
use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
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
use App\Services\VmProvisioningService;
use App\Services\Vps\AllowAllVpsPlanChangeAuthorizer;
use App\Services\Vps\AllowAllVpsProvisioningAuthorizer;
use App\Services\Vps\VpsPlanChangeService;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Failed as VmFailed;
use App\States\Vm\Running as VmRunning;
use App\States\VpsPlanChange\AwaitingAuthorization;
use App\States\VpsPlanChange\Cancelled;
use App\States\VpsPlanChange\Failed;
use App\States\VpsPlanChange\Pending;
use App\ValueObjects\Hypervisor\InstanceResourceSpec;
use App\ValueObjects\Hypervisor\NetworkLimitsSpec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

afterEach(function (): void {
    HypervisorManager::resetFake();
});

/**
 * @return array{
 *     user: User,
 *     admin: User,
 *     host: Host,
 *     pool: IpPool,
 *     image: Image,
 *     plan: VpsPlan,
 *     fromVersion: VpsPlanVersion,
 *     toVersion: VpsPlanVersion,
 *     vm: VirtualMachine,
 *     driver: FakeHypervisorDriver,
 * }
 */
function setupAuthorizerTestEnvironment(): array
{
    config(['app.allow_fake_driver' => true]);

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

    UserQuota::query()->create([
        'user_id' => $admin->id,
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

    $image = Image::firstOrCreate(
        ['name' => 'ubuntu/24.04'],
        [
            'source' => 'https://images.linuxcontainers.org',
            'protocol' => 'simplestreams',
            'os_type' => 'linux',
            'enabled' => true,
        ]
    );
    $image->hosts()->syncWithoutDetaching([$host->id]);

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

    $plan->current_version_id = $toVersion->id;
    $plan->save();

    $vm = VirtualMachine::factory()->create([
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
    ]);

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
        network: new NetworkLimitsSpec($vm->net_download_mbps, $vm->net_upload_mbps),
    ));

    return [
        'user' => $user,
        'admin' => $admin,
        'host' => $host,
        'pool' => $pool,
        'image' => $image,
        'plan' => $plan,
        'fromVersion' => $fromVersion,
        'toVersion' => $toVersion,
        'vm' => $vm->fresh(['host', 'primaryAllocation.pool']),
        'driver' => $driver,
    ];
}

it('resolves default allow-all authorizers from the container', function (): void {
    expect(app(VpsPlanChangeAuthorizerInterface::class))->toBeInstanceOf(AllowAllVpsPlanChangeAuthorizer::class)
        ->and(app(VpsProvisioningAuthorizerInterface::class))->toBeInstanceOf(AllowAllVpsProvisioningAuthorizer::class);
});

it('invokes VpsProvisioningAuthorizerInterface outside transaction and allows provisioning with initiator metadata', function (): void {
    Queue::fake();

    $env = setupAuthorizerTestEnvironment();
    $captured = [];

    app()->bind(VpsProvisioningAuthorizerInterface::class, static function () use (&$captured): VpsProvisioningAuthorizerInterface {
        return new class($captured) implements VpsProvisioningAuthorizerInterface
        {
            /** @param list<array{request: VpsProvisioningAuthorizationRequest, txLevel: int}> $captured */
            public function __construct(private array &$captured) {}

            public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult
            {
                $this->captured[] = [
                    'request' => $request,
                    'txLevel' => DB::transactionLevel(),
                ];

                return new VpsProvisioningAuthorizationResult(status: VpsAuthorizationStatus::Allowed, reference: 'prov-ok-1');
            }
        };
    });

    $service = app(VmProvisioningService::class);
    $baselineTxLevel = DB::transactionLevel();

    $customerVm = $service->provision($env['user'], [
        'name' => 'cust-plan-vm',
        'os_image' => 'ubuntu/24.04',
        'host_id' => $env['host']->id,
        'vps_plan_id' => $env['plan']->id,
    ]);

    $adminVm = $service->provision($env['admin'], [
        'name' => 'admin-plan-vm',
        'os_image' => 'ubuntu/24.04',
        'host_id' => $env['host']->id,
        'vps_plan_id' => $env['plan']->id,
    ]);

    expect($customerVm->vps_plan_version_id)->toBe($env['toVersion']->id)
        ->and($adminVm->vps_plan_version_id)->toBe($env['toVersion']->id)
        ->and($captured)->toHaveCount(2);

    expect($captured[0]['txLevel'])->toBe($baselineTxLevel)
        ->and($captured[0]['request']->userId)->toBe($env['user']->id)
        ->and($captured[0]['request']->virtualMachineId)->toBeNull()
        ->and($captured[0]['request']->fromVersionId)->toBeNull()
        ->and($captured[0]['request']->toVersionId)->toBe($env['toVersion']->id)
        ->and($captured[0]['request']->oldResources)->toBe([])
        ->and($captured[0]['request']->newResources)->toBe([
            'cpu_cores' => 4,
            'ram_mb' => 4096,
            'disk_gb' => 40,
            'net_download_mbps' => 1000,
            'net_upload_mbps' => 1000,
        ])
        ->and($captured[0]['request']->initiator->id)->toBe($env['user']->id)
        ->and($captured[0]['request']->initiator->isAdmin)->toBeFalse();

    expect($captured[1]['request']->initiator->id)->toBe($env['admin']->id)
        ->and($captured[1]['request']->initiator->isAdmin)->toBeTrue();

    Queue::assertPushed(ProvisionVmJob::class, 2);
});

it('rejects synchronous provisioning when authorizer returns denied or pending', function (VpsAuthorizationStatus $status): void {
    Queue::fake();

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsProvisioningAuthorizerInterface::class, static fn (): VpsProvisioningAuthorizerInterface => new class($status) implements VpsProvisioningAuthorizerInterface
    {
        public function __construct(private readonly VpsAuthorizationStatus $status) {}

        public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult
        {
            return new VpsProvisioningAuthorizationResult(
                status: $this->status,
                reasonCode: 'BILLING_HOLD',
                safeMessage: 'Account authorization rejected.',
            );
        }
    });

    $service = app(VmProvisioningService::class);
    $initialVmCount = VirtualMachine::query()->count();

    try {
        $service->provision($env['user'], [
            'name' => 'denied-vm',
            'os_image' => 'ubuntu/24.04',
            'host_id' => $env['host']->id,
            'vps_plan_id' => $env['plan']->id,
        ]);
        $this->fail('Expected ValidationException when provisioning authorizer rejects.');
    } catch (ValidationException $e) {
        $payload = $e->response?->getData(true);
        expect($e->status)->toBe(422)
            ->and(is_array($payload) ? ($payload['code'] ?? null) : null)->toBe('EXTENSION_AUTHORIZATION_DENIED');
    }

    expect(VirtualMachine::query()->count())->toBe($initialVmCount);
    Queue::assertNotPushed(ProvisionVmJob::class);
})->with([
    'denied' => VpsAuthorizationStatus::Denied,
    'pending' => VpsAuthorizationStatus::Pending,
]);

it('throws 503 EXTENSION_AUTHORIZATION_UNAVAILABLE when provisioning authorizer throws or times out', function (bool $simulateTimeout): void {
    Queue::fake();
    config(['vps.authorizer_timeout_seconds' => 1]);

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsProvisioningAuthorizerInterface::class, static fn (): VpsProvisioningAuthorizerInterface => new class($simulateTimeout) implements VpsProvisioningAuthorizerInterface
    {
        public function __construct(private readonly bool $simulateTimeout) {}

        public function authorize(VpsProvisioningAuthorizationRequest $request): VpsProvisioningAuthorizationResult
        {
            if ($this->simulateTimeout) {
                sleep(2);

                return new VpsProvisioningAuthorizationResult(status: VpsAuthorizationStatus::Allowed);
            }

            throw new RuntimeException('Billing endpoint unreachable.');
        }
    });

    $service = app(VmProvisioningService::class);
    $initialVmCount = VirtualMachine::query()->count();

    try {
        $service->provision($env['user'], [
            'name' => 'timeout-vm',
            'os_image' => 'ubuntu/24.04',
            'host_id' => $env['host']->id,
            'vps_plan_id' => $env['plan']->id,
        ]);
        $this->fail('Expected 503 HttpException when provisioning authorizer is unavailable.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->getMessage())->toContain('EXTENSION_AUTHORIZATION_UNAVAILABLE');
    }

    expect(VirtualMachine::query()->count())->toBe($initialVmCount);
    Queue::assertNotPushed(ProvisionVmJob::class);
})->with([
    'exception' => false,
    'timeout' => true,
]);

it('invokes VpsPlanChangeAuthorizerInterface outside transaction for allowed requests and records initiator.isAdmin', function (): void {
    Queue::fake();

    $env = setupAuthorizerTestEnvironment();
    $captured = [];

    app()->bind(VpsPlanChangeAuthorizerInterface::class, static function () use (&$captured): VpsPlanChangeAuthorizerInterface {
        return new class($captured) implements VpsPlanChangeAuthorizerInterface
        {
            /** @param list<array{request: VpsPlanChangeAuthorizationRequest, txLevel: int}> $captured */
            public function __construct(private array &$captured) {}

            public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
            {
                $this->captured[] = [
                    'request' => $request,
                    'txLevel' => DB::transactionLevel(),
                ];

                return new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Allowed, reference: 'allow-ref-1');
            }
        };
    });

    $service = app(VpsPlanChangeService::class);
    $baselineTxLevel = DB::transactionLevel();

    $change = $service->request($env['vm'], $env['toVersion'], $env['admin'], isAdmin: true);

    expect($change->status)->toBeInstanceOf(Pending::class)
        ->and($change->stage)->toBeNull()
        ->and($change->authorization_ref)->toBe('allow-ref-1')
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]['txLevel'])->toBe($baselineTxLevel)
        ->and($captured[0]['request']->requestId)->toBe($change->public_id)
        ->and($captured[0]['request']->userId)->toBe($env['user']->id)
        ->and($captured[0]['request']->virtualMachineId)->toBe($env['vm']->id)
        ->and($captured[0]['request']->fromVersionId)->toBe($env['fromVersion']->id)
        ->and($captured[0]['request']->toVersionId)->toBe($env['toVersion']->id)
        ->and($captured[0]['request']->initiator->id)->toBe($env['admin']->id)
        ->and($captured[0]['request']->initiator->isAdmin)->toBeTrue();

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, fn (ApplyVpsPlanChangeJob $job): bool => $job->planChangeId === $change->id);
});

it('rejects plan change request with EXTENSION_AUTHORIZATION_DENIED and creates no row when authorizer denies', function (): void {
    Queue::fake();

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsPlanChangeAuthorizerInterface::class, static fn (): VpsPlanChangeAuthorizerInterface => new class implements VpsPlanChangeAuthorizerInterface
    {
        public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
        {
            return new VpsPlanChangeAuthorizationResult(
                status: VpsAuthorizationStatus::Denied,
                reasonCode: 'UNPAID_INVOICE',
                safeMessage: 'Outstanding invoice must be settled before upgrading.',
            );
        }
    });

    $service = app(VpsPlanChangeService::class);

    try {
        $service->request($env['vm'], $env['toVersion'], $env['user'], isAdmin: false);
        $this->fail('Expected ValidationException when plan change authorizer denies.');
    } catch (ValidationException $e) {
        $payload = $e->response?->getData(true);
        expect($e->status)->toBe(422)
            ->and(is_array($payload) ? ($payload['code'] ?? null) : null)->toBe('EXTENSION_AUTHORIZATION_DENIED');
    }

    expect(VpsPlanChange::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', AuditAction::VpsPlanChangeDenied->value)->count())->toBe(1);
    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);
});

it('throws 503 EXTENSION_AUTHORIZATION_UNAVAILABLE and creates no row when plan change authorizer throws or times out', function (bool $simulateTimeout): void {
    Queue::fake();
    config(['vps.authorizer_timeout_seconds' => 1]);

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsPlanChangeAuthorizerInterface::class, static fn (): VpsPlanChangeAuthorizerInterface => new class($simulateTimeout) implements VpsPlanChangeAuthorizerInterface
    {
        public function __construct(private readonly bool $simulateTimeout) {}

        public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
        {
            if ($this->simulateTimeout) {
                sleep(2);

                return new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Allowed);
            }

            throw new RuntimeException('External authorizer HTTP 500');
        }
    });

    $service = app(VpsPlanChangeService::class);

    try {
        $service->request($env['vm'], $env['toVersion'], $env['user']);
        $this->fail('Expected 503 HttpException when plan change authorizer is unavailable.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(503)
            ->and($e->getMessage())->toContain('EXTENSION_AUTHORIZATION_UNAVAILABLE');
    }

    expect(VpsPlanChange::query()->count())->toBe(0);
    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);
})->with([
    'exception' => false,
    'timeout' => true,
]);

it('pauses in AwaitingAuthorization without dispatching job when authorizer returns pending, and resumes to Pending on allowed', function (): void {
    Queue::fake();

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsPlanChangeAuthorizerInterface::class, static fn (): VpsPlanChangeAuthorizerInterface => new class implements VpsPlanChangeAuthorizerInterface
    {
        public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
        {
            return new VpsPlanChangeAuthorizationResult(
                status: VpsAuthorizationStatus::Pending,
                reference: 'ext-auth-123',
            );
        }
    });

    $service = app(VpsPlanChangeService::class);
    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);

    expect($change->status)->toBeInstanceOf(AwaitingAuthorization::class)
        ->and($change->stage)->toBe('authorization')
        ->and($change->authorization_ref)->toBe('ext-auth-123');

    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);

    $resumed = $service->resumeAuthorization(
        $change->public_id,
        new VpsPlanChangeAuthorizationResult(
            status: VpsAuthorizationStatus::Allowed,
            reference: 'ext-auth-123-approved',
        ),
    );

    expect($resumed->status)->toBeInstanceOf(Pending::class)
        ->and($resumed->stage)->toBeNull()
        ->and($resumed->authorization_ref)->toBe('ext-auth-123-approved');

    Queue::assertPushed(ApplyVpsPlanChangeJob::class, fn (ApplyVpsPlanChangeJob $job): bool => $job->planChangeId === $change->id);

    // Calling resumeAuthorization again fails CAS check
    expect(fn () => $service->resumeAuthorization(
        $change->public_id,
        new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Allowed),
    ))->toThrow(ConflictHttpException::class);
});

it('transitions AwaitingAuthorization to Failed on resumeAuthorization when denied or when VM is no longer eligible', function (): void {
    Queue::fake();
    Event::fake([VpsPlanChangeFailed::class]);

    $env = setupAuthorizerTestEnvironment();

    app()->bind(VpsPlanChangeAuthorizerInterface::class, static fn (): VpsPlanChangeAuthorizerInterface => new class implements VpsPlanChangeAuthorizerInterface
    {
        public function authorize(VpsPlanChangeAuthorizationRequest $request): VpsPlanChangeAuthorizationResult
        {
            return new VpsPlanChangeAuthorizationResult(
                status: VpsAuthorizationStatus::Pending,
                reference: 'ext-auth-deny',
            );
        }
    });

    $service = app(VpsPlanChangeService::class);

    // Case 1: Denied with custom reasonCode
    $change = $service->request($env['vm'], $env['toVersion'], $env['user']);
    $failed = $service->resumeAuthorization(
        $change->public_id,
        new VpsPlanChangeAuthorizationResult(
            status: VpsAuthorizationStatus::Denied,
            reasonCode: 'PAYMENT_FAILED',
            safeMessage: 'Card payment failed.',
        ),
    );

    expect($failed->status)->toBeInstanceOf(Failed::class)
        ->and($failed->error_code)->toBe('PAYMENT_FAILED')
        ->and($failed->error_message)->toBe('Card payment failed.')
        ->and($failed->failed_at)->not->toBeNull();

    Event::assertDispatched(VpsPlanChangeFailed::class, fn (VpsPlanChangeFailed $e): bool => $e->planChangeId === $change->id && $e->errorCode === 'PAYMENT_FAILED');
    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);

    // Case 2: Denied without reasonCode falls back to EXTENSION_AUTHORIZATION_DENIED
    $changeFallback = $service->request($env['vm'], $env['toVersion'], $env['user']);
    $failedFallback = $service->resumeAuthorization(
        $changeFallback->public_id,
        new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Denied),
    );

    expect($failedFallback->status)->toBeInstanceOf(Failed::class)
        ->and($failedFallback->error_code)->toBe('EXTENSION_AUTHORIZATION_DENIED')
        ->and($failedFallback->failed_at)->not->toBeNull();

    // Case 3: Allowed resume fails VM state revalidation -> Failed
    $changeRevalidate = $service->request($env['vm'], $env['toVersion'], $env['user']);
    DB::table('virtual_machines')->where('id', $env['vm']->id)->update(['status' => VmFailed::$name]);

    $failedRevalidation = $service->resumeAuthorization(
        $changeRevalidate->public_id,
        new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Allowed),
    );

    expect($failedRevalidation->status)->toBeInstanceOf(Failed::class)
        ->and($failedRevalidation->error_code)->toBe('VM_STATE_NOT_SUPPORTED')
        ->and($failedRevalidation->failed_at)->not->toBeNull();

    Queue::assertNotPushed(ApplyVpsPlanChangeJob::class);

    // Case 4: Cancelled awaiting_authorization cannot be resumed
    DB::table('virtual_machines')->where('id', $env['vm']->id)->update(['status' => VmRunning::$name]);
    $changeCancelled = $service->request($env['vm'], $env['toVersion'], $env['user']);
    $cancelled = $service->cancel($changeCancelled, $env['user']);
    expect($cancelled->status)->toBeInstanceOf(Cancelled::class);

    expect(fn () => $service->resumeAuthorization(
        $changeCancelled->public_id,
        new VpsPlanChangeAuthorizationResult(status: VpsAuthorizationStatus::Allowed),
    ))->toThrow(ConflictHttpException::class);
});
