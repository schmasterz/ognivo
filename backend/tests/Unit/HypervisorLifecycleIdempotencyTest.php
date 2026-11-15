<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\Drivers\LxdDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HypervisorLifecycleIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Host $incusHost;

    private Host $lxdHost;

    private VirtualMachine $vm;

    private PanelCertificate $cert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'project_name' => 'tenant-idempotent-proj',
        ]);

        $this->cert = app(PanelCertificateService::class)->generate(365);

        $this->incusHost = Host::create([
            'name' => 'incus-idempotent-node',
            'endpoint_url' => 'https://incus-node.local:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('i', 32)),
            'is_active' => true,
        ]);

        $this->lxdHost = Host::create([
            'name' => 'lxd-idempotent-node',
            'endpoint_url' => 'https://lxd-node.local:8443',
            'driver' => 'lxd',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('l', 32)),
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->incusHost->id,
            'name' => 'vm-idempotent-01',
            'incus_project' => 'tenant-idempotent-proj',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_stop_instance_succeeds_idempotently_when_already_stopped(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-stop-101',
            ], 202),
            'https://incus-node.local:8443/1.0/operations/op-stop-101/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'The instance is already stopped',
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->stopInstance($this->vm));
    }

    public function test_stop_instance_succeeds_idempotently_on_synchronous_response(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'error',
                'error' => 'The instance is already stopped',
                'error_code' => 400,
            ], 400),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->stopInstance($this->vm));
    }

    public function test_start_instance_succeeds_idempotently_when_already_running(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-start-102',
            ], 202),
            'https://incus-node.local:8443/1.0/operations/op-start-102/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'The instance is already running',
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->startInstance($this->vm));
    }

    public function test_start_instance_succeeds_idempotently_on_synchronous_response(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'error',
                'error' => 'The instance is already running',
                'error_code' => 400,
            ], 400),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->startInstance($this->vm));
    }

    public function test_stop_instance_fails_on_genuine_error(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-stop-err',
            ], 202),
            'https://incus-node.local:8443/1.0/operations/op-stop-err/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'Permission denied: unable to lock instance rootfs',
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertFalse($driver->stopInstance($this->vm));
        $this->assertSame('Permission denied: unable to lock instance rootfs', $driver->getLastError());
    }

    public function test_suspend_instance_succeeds_idempotently_when_already_frozen(): void
    {
        Http::fake([
            'https://incus-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-suspend-103',
            ], 202),
            'https://incus-node.local:8443/1.0/operations/op-suspend-103/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'The instance is already frozen',
                ],
            ], 200),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->assertTrue($driver->suspendInstance($this->vm));
    }

    public function test_lxd_driver_stop_instance_succeeds_idempotently_when_already_stopped(): void
    {
        $this->vm->update(['host_id' => $this->lxdHost->id]);

        Http::fake([
            'https://lxd-node.local:8443/1.0/instances/vm-idempotent-01/state?project=tenant-idempotent-proj' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-lxd-stop-104',
            ], 202),
            'https://lxd-node.local:8443/1.0/operations/op-lxd-stop-104/wait*' => Http::response([
                'type' => 'sync',
                'status' => 'Failure',
                'status_code' => 400,
                'metadata' => [
                    'status' => 'Failure',
                    'status_code' => 400,
                    'err' => 'The instance is already stopped',
                ],
            ], 200),
        ]);

        $driver = new LxdDriver($this->lxdHost);

        $this->assertTrue($driver->stopInstance($this->vm));
    }
}
