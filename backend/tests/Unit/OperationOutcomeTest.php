<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\AbstractIncusLxdDriver;
use App\Services\Hypervisor\Drivers\OperationOutcome;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class OperationOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private Host $incusHost;

    protected function setUp(): void
    {
        parent::setUp();

        app(PanelCertificateService::class)->generate(365);

        $this->incusHost = Host::create([
            'name' => 'incus-outcome-node',
            'endpoint_url' => 'https://incus-outcome.local:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('o', 32)),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    private function makeTestableDriver(): object
    {
        return new class($this->incusHost) extends AbstractIncusLxdDriver
        {
            public function getDriverName(): string
            {
                return 'incus';
            }

            /**
             * @param  list<string>  $acceptableErrors
             */
            public function runExecuteOperation(
                Response $response,
                ?string $project = null,
                array $acceptableErrors = [],
                ?int $waitSeconds = null,
            ): OperationOutcome {
                return $this->executeOperation($response, $project, $acceptableErrors, $waitSeconds);
            }

            /**
             * @param  list<string>  $acceptableErrors
             */
            public function runHandleOperationResponse(
                Response $response,
                ?string $project = null,
                array $acceptableErrors = [],
            ): bool {
                return $this->handleOperationResponse($response, $project, $acceptableErrors);
            }
        };
    }

    public function test_operation_outcome_value_object_helpers(): void
    {
        $succeeded = OperationOutcome::succeeded('op-1');
        $this->assertSame(OperationOutcome::STATUS_SUCCEEDED, $succeeded->status);
        $this->assertTrue($succeeded->isSucceeded());
        $this->assertFalse($succeeded->isRunning());
        $this->assertFalse($succeeded->isFailed());
        $this->assertSame('op-1', $succeeded->operationId);
        $this->assertNull($succeeded->errorMessage);

        $running = OperationOutcome::running('op-2');
        $this->assertSame(OperationOutcome::STATUS_RUNNING, $running->status);
        $this->assertTrue($running->isRunning());
        $this->assertFalse($running->isSucceeded());
        $this->assertFalse($running->isFailed());
        $this->assertSame('op-2', $running->operationId);
        $this->assertNull($running->errorMessage);

        $failed = OperationOutcome::failed('Disk resize failed', 'op-3');
        $this->assertSame(OperationOutcome::STATUS_FAILED, $failed->status);
        $this->assertTrue($failed->isFailed());
        $this->assertFalse($failed->isSucceeded());
        $this->assertFalse($failed->isRunning());
        $this->assertSame('op-3', $failed->operationId);
        $this->assertSame('Disk resize failed', $failed->errorMessage);
    }

    public function test_execute_operation_returns_succeeded_for_sync_and_async_responses(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/sync-ok' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
            ], 200),
            'https://incus-outcome.local:8443/1.0/async-ok' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-async-ok',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-async-ok/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'id' => 'op-async-ok',
                    'status' => 'Success',
                    'status_code' => 200,
                ],
            ], 200),
        ]);

        $driver = $this->makeTestableDriver();

        $syncResponse = Http::get('https://incus-outcome.local:8443/1.0/sync-ok');
        $syncOutcome = $driver->runExecuteOperation($syncResponse, 'proj-1');
        $this->assertTrue($syncOutcome->isSucceeded());
        $this->assertNull($driver->getLastError());

        $asyncResponse = Http::get('https://incus-outcome.local:8443/1.0/async-ok');
        $asyncOutcome = $driver->runExecuteOperation($asyncResponse, 'proj-1', [], 30);
        $this->assertTrue($asyncOutcome->isSucceeded());
        $this->assertSame('op-async-ok', $asyncOutcome->operationId);
        $this->assertNull($driver->getLastError());

        Http::assertSent(static function (ClientRequest $request): bool {
            return str_contains($request->url(), '/1.0/operations/op-async-ok/wait')
                && str_contains($request->url(), 'timeout=30')
                && str_contains($request->url(), 'project=proj-1');
        });
    }

    public function test_execute_operation_returns_running_when_still_running_after_short_wait(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/async-running' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-running-30s',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-running-30s/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'id' => 'op-running-30s',
                    'status' => 'Running',
                    'status_code' => 103,
                ],
            ], 200),
        ]);

        $driver = $this->makeTestableDriver();
        $initialResponse = Http::get('https://incus-outcome.local:8443/1.0/async-running');
        $outcome = $driver->runExecuteOperation($initialResponse, 'proj-1', [], 30);

        $this->assertTrue($outcome->isRunning());
        $this->assertSame('op-running-30s', $outcome->operationId);
        $this->assertNull($outcome->errorMessage);
        $this->assertNull($driver->getLastError());
    }

    public function test_execute_operation_returns_running_when_wait_times_out_over_http(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/async-timeout' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-http-timeout',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-http-timeout/wait*' => static function (): never {
                throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds');
            },
        ]);

        $driver = $this->makeTestableDriver();
        $initialResponse = Http::get('https://incus-outcome.local:8443/1.0/async-timeout');
        $outcome = $driver->runExecuteOperation($initialResponse, 'proj-1', [], 30);

        $this->assertTrue($outcome->isRunning());
        $this->assertSame('op-http-timeout', $outcome->operationId);
        $this->assertNull($driver->getLastError());
    }

    public function test_execute_operation_returns_failed_on_sync_and_async_errors(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/sync-fail' => Http::response([
                'type' => 'error',
                'error' => 'Insufficient storage space',
                'error_code' => 400,
            ], 400),
            'https://incus-outcome.local:8443/1.0/async-fail' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-async-fail',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-async-fail/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'id' => 'op-async-fail',
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'Cannot shrink disk online',
                ],
            ], 200),
        ]);

        $driver = $this->makeTestableDriver();

        $syncFail = Http::get('https://incus-outcome.local:8443/1.0/sync-fail');
        $syncOutcome = $driver->runExecuteOperation($syncFail, 'proj-1');
        $this->assertTrue($syncOutcome->isFailed());
        $this->assertSame('Insufficient storage space', $syncOutcome->errorMessage);
        $this->assertSame('Insufficient storage space', $driver->getLastError());

        $asyncFail = Http::get('https://incus-outcome.local:8443/1.0/async-fail');
        $asyncOutcome = $driver->runExecuteOperation($asyncFail, 'proj-1', [], 30);
        $this->assertTrue($asyncOutcome->isFailed());
        $this->assertSame('op-async-fail', $asyncOutcome->operationId);
        $this->assertSame('Cannot shrink disk online', $asyncOutcome->errorMessage);
        $this->assertSame('Cannot shrink disk online', $driver->getLastError());
    }

    public function test_execute_operation_treats_acceptable_errors_as_succeeded(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/sync-idempotent' => Http::response([
                'type' => 'error',
                'error' => 'The instance is already stopped',
                'error_code' => 400,
            ], 400),
            'https://incus-outcome.local:8443/1.0/async-idempotent' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-idempotent',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-idempotent/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'id' => 'op-idempotent',
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'The instance is already running',
                ],
            ], 200),
        ]);

        $driver = $this->makeTestableDriver();

        $syncRes = Http::get('https://incus-outcome.local:8443/1.0/sync-idempotent');
        $syncOutcome = $driver->runExecuteOperation($syncRes, 'proj-1', ['already stopped']);
        $this->assertTrue($syncOutcome->isSucceeded());
        $this->assertNull($driver->getLastError());

        $asyncRes = Http::get('https://incus-outcome.local:8443/1.0/async-idempotent');
        $asyncOutcome = $driver->runExecuteOperation($asyncRes, 'proj-1', ['already running'], 30);
        $this->assertTrue($asyncOutcome->isSucceeded());
        $this->assertSame('op-idempotent', $asyncOutcome->operationId);
        $this->assertNull($driver->getLastError());
    }

    public function test_handle_operation_response_preserves_backwards_compatibility_including_120s_timeout(): void
    {
        Http::fake([
            'https://incus-outcome.local:8443/1.0/legacy-still-running' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-legacy-120s',
            ], 202),
            'https://incus-outcome.local:8443/1.0/operations/op-legacy-120s/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
                'metadata' => [
                    'id' => 'op-legacy-120s',
                    'status' => 'Running',
                    'status_code' => 103,
                ],
            ], 200),
            'https://incus-outcome.local:8443/1.0/legacy-ok' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
            ], 200),
        ]);

        $driver = $this->makeTestableDriver();

        $runningResponse = Http::get('https://incus-outcome.local:8443/1.0/legacy-still-running');
        $this->assertFalse($driver->runHandleOperationResponse($runningResponse, 'proj-legacy'));
        $this->assertNotNull($driver->getLastError());

        Http::assertSent(static function (ClientRequest $request): bool {
            return str_contains($request->url(), '/1.0/operations/op-legacy-120s/wait')
                && str_contains($request->url(), 'timeout=120')
                && str_contains($request->url(), 'project=proj-legacy');
        });

        $okResponse = Http::get('https://incus-outcome.local:8443/1.0/legacy-ok');
        $this->assertTrue($driver->runHandleOperationResponse($okResponse, 'proj-legacy'));
        $this->assertNull($driver->getLastError());
    }

    public function test_plan_change_gates_policy_and_rate_limiter_authorization(): void
    {
        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'customer']);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $owner = User::factory()->create();
        $owner->assignRole('customer');

        $otherUser = User::factory()->create();
        $otherUser->assignRole('customer');

        $vm = VirtualMachine::create([
            'user_id' => $owner->id,
            'host_id' => $this->incusHost->id,
            'name' => 'vm-policy-01',
            'incus_project' => 'proj-owner',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        // Gate abilities
        $this->assertTrue(Gate::forUser($admin)->allows('vps.plans.manage'));
        $this->assertFalse(Gate::forUser($owner)->allows('vps.plans.manage'));

        $this->assertTrue(Gate::forUser($admin)->allows('vps.plan_change.manage'));
        $this->assertFalse(Gate::forUser($owner)->allows('vps.plan_change.manage'));

        $this->assertTrue(Gate::forUser($admin)->allows('vps.plan_change.request'));
        $this->assertTrue(Gate::forUser($owner)->allows('vps.plan_change.request'));
        $this->assertFalse(Gate::forUser(null)->allows('vps.plan_change.request'));

        // Policy VirtualMachinePolicy::changePlan
        $this->assertTrue($admin->can('changePlan', $vm));
        $this->assertTrue($owner->can('changePlan', $vm));
        $this->assertFalse($otherUser->can('changePlan', $vm));

        // Rate limiter plan-change-preview
        $limiter = RateLimiter::limiter('plan-change-preview');
        $this->assertNotNull($limiter);

        $adminRequest = Request::create('/api/v1/vms/1/plan-change/preview', 'POST');
        $adminRequest->setUserResolver(static fn (): User => $admin);
        $this->assertInstanceOf(Unlimited::class, $limiter($adminRequest));

        $customerRequest = Request::create('/api/v1/vms/1/plan-change/preview', 'POST');
        $customerRequest->setUserResolver(static fn (): User => $owner);
        $customerLimit = $limiter($customerRequest);
        $this->assertNotInstanceOf(Unlimited::class, $customerLimit);
    }
}
