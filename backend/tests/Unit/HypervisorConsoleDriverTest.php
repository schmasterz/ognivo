<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use App\Services\Terminal\Drivers\ConsoleDriver;
use App\Services\Terminal\Drivers\ExecDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class HypervisorConsoleDriverTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Host $incusHost;

    private VirtualMachine $vm;

    private VirtualMachine $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'project_name' => 'tenant-console-proj',
        ]);

        app(PanelCertificateService::class)->generate(365);

        $this->incusHost = Host::create([
            'name' => 'incus-console-node',
            'endpoint_url' => 'https://incus-console.local:8443',
            'driver' => 'incus',
            'server_public_key_pin' => 'sha256//'.base64_encode(str_repeat('p', 32)),
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->incusHost->id,
            'name' => 'vm-console-01',
            'incus_project' => 'tenant-console-proj',
            'instance_type' => 'virtual-machine',
            'status' => 'running',
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $this->container = VirtualMachine::create([
            'user_id' => $this->user->id,
            'host_id' => $this->incusHost->id,
            'name' => 'ct-console-01',
            'incus_project' => 'tenant-console-proj',
            'instance_type' => 'container',
            'status' => 'running',
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        parent::tearDown();
    }

    public function test_fake_driver_creates_console_session_for_vm(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $session = $fakeDriver->createConsoleSession($this->vm, 'console', 100, 30);

        $this->assertSame('fake-op-console-'.$this->vm->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-data-'.$this->vm->id, $session['websocket_path']);
        $this->assertNotNull($session['control_path']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('console', $session['driver']);
    }

    public function test_fake_driver_creates_console_session_for_container(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $session = $fakeDriver->createConsoleSession($this->container, 'console');

        $this->assertSame('fake-op-console-'.$this->container->id, $session['operation_id']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('console', $session['driver']);
    }

    public function test_fake_driver_creates_exec_session_for_vm(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $session = $fakeDriver->createExecSession($this->vm, 120, 35);

        $this->assertSame('fake-op-exec-'.$this->vm->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-exec-data-'.$this->vm->id, $session['websocket_path']);
        $this->assertNotNull($session['control_path']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('exec', $session['driver']);
    }

    public function test_fake_driver_creates_exec_session_for_container(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $session = $fakeDriver->createExecSession($this->container);

        $this->assertSame('fake-op-exec-'.$this->container->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-exec-data-'.$this->container->id, $session['websocket_path']);
        $this->assertSame('exec', $session['driver']);
    }

    public function test_fake_driver_exec_session_throws_when_guest_agent_not_running(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => false]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("VM agent isn't currently running for instance ".$this->vm->name);

        $fakeDriver->createExecSession($this->vm);
    }

    public function test_fake_driver_rejects_vga_on_container(): void
    {
        $fakeDriver = new FakeHypervisorDriver;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Containers do not support graphical VGA console.');

        $fakeDriver->createConsoleSession($this->container, 'vga');
    }

    public function test_fake_driver_cancels_operation(): void
    {
        $fakeDriver = new FakeHypervisorDriver;

        $result = $fakeDriver->cancelOperation($this->vm, 'test-op-console-1234');

        $this->assertTrue($result);
        $this->assertContains('test-op-console-1234', $fakeDriver->cancelledOperations);
    }

    public function test_incus_driver_sends_correct_console_post_request(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/instances/vm-console-01/console*' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-uuid-12345',
                'metadata' => [
                    'id' => 'op-uuid-12345',
                    'class' => 'websocket',
                    'metadata' => [
                        'fds' => [
                            '0' => 'secret-data-token',
                            'control' => 'secret-ctrl-token',
                        ],
                    ],
                ],
            ], 202),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $session = $driver->createConsoleSession($this->vm, 'console', 100, 30);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/1.0/instances/vm-console-01/console')
                && $request['type'] === 'console'
                && $request['width'] === 100
                && $request['height'] === 30
                && $request['wait-for-websocket'] === true
                && str_contains($request->url(), 'project=tenant-console-proj')
                && str_contains($request->url(), 'wait-for-websocket=true');
        });

        $this->assertSame('op-uuid-12345', $session['operation_id']);
        $this->assertSame('/1.0/operations/op-uuid-12345/websocket?secret=secret-data-token', $session['websocket_path']);
        $this->assertSame('/1.0/operations/op-uuid-12345/websocket?secret=secret-ctrl-token', $session['control_path']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('console', $session['driver']);
    }

    public function test_incus_driver_sends_correct_console_post_request_for_container(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/instances/ct-console-01/console*' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-uuid-ct-console',
                'metadata' => [
                    'id' => 'op-uuid-ct-console',
                    'class' => 'websocket',
                    'metadata' => [
                        'fds' => [
                            '0' => 'secret-data-ct-token',
                            'control' => 'secret-ctrl-ct-token',
                        ],
                    ],
                ],
            ], 202),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $session = $driver->createConsoleSession($this->container, 'console', 80, 24);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/1.0/instances/ct-console-01/console')
                && $request['type'] === 'console'
                && $request['width'] === 80
                && $request['height'] === 24
                && $request['wait-for-websocket'] === true
                && str_contains($request->url(), 'project=tenant-console-proj');
        });

        $this->assertSame('op-uuid-ct-console', $session['operation_id']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('console', $session['driver']);
    }

    public function test_incus_driver_sends_correct_exec_post_request_for_container(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/instances/ct-console-01/exec*' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-uuid-container-exec',
                'metadata' => [
                    'id' => 'op-uuid-container-exec',
                    'class' => 'websocket',
                    'metadata' => [
                        'fds' => [
                            '0' => 'secret-data-ct-token',
                            'control' => 'secret-ctrl-ct-token',
                        ],
                    ],
                ],
            ], 202),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $session = $driver->createExecSession($this->container, 90, 28);

        Http::assertSent(function (Request $request): bool {
            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/1.0/instances/ct-console-01/exec')
                && str_contains($request->url(), 'project=tenant-console-proj')
                && ($data['interactive'] ?? null) === true
                && ($data['wait-for-websocket'] ?? null) === true
                && ($data['width'] ?? null) === 90
                && ($data['height'] ?? null) === 28
                && isset($data['environment']) && is_array($data['environment'])
                && ($data['environment']['TERM'] ?? null) === 'xterm-256color'
                && isset($data['command']) && is_array($data['command'])
                && in_array('/bin/sh', $data['command'], true);
        });

        $this->assertSame('op-uuid-container-exec', $session['operation_id']);
        $this->assertSame('/1.0/operations/op-uuid-container-exec/websocket?secret=secret-data-ct-token', $session['websocket_path']);
        $this->assertSame('/1.0/operations/op-uuid-container-exec/websocket?secret=secret-ctrl-ct-token', $session['control_path']);
        $this->assertSame('console', $session['type']);
        $this->assertSame('exec', $session['driver']);
    }

    public function test_incus_driver_sends_correct_exec_post_request_for_vm(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/instances/vm-console-01/exec*' => Http::response([
                'type' => 'async',
                'status' => 'Operation created',
                'status_code' => 100,
                'operation' => '/1.0/operations/op-uuid-vm-exec',
                'metadata' => [
                    'id' => 'op-uuid-vm-exec',
                    'class' => 'websocket',
                    'metadata' => [
                        'fds' => [
                            '0' => 'secret-data-vm-token',
                            'control' => 'secret-ctrl-vm-token',
                        ],
                    ],
                ],
            ], 202),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $session = $driver->createExecSession($this->vm, 120, 40);

        Http::assertSent(function (Request $request): bool {
            /** @var array<string, mixed> $data */
            $data = $request->data();

            return $request->method() === 'POST'
                && str_contains($request->url(), '/1.0/instances/vm-console-01/exec')
                && str_contains($request->url(), 'project=tenant-console-proj')
                && ($data['interactive'] ?? null) === true
                && ($data['wait-for-websocket'] ?? null) === true
                && ($data['width'] ?? null) === 120
                && ($data['height'] ?? null) === 40
                && isset($data['environment']) && is_array($data['environment'])
                && ($data['environment']['TERM'] ?? null) === 'xterm-256color'
                && isset($data['command']) && is_array($data['command'])
                && count($data['command']) === 3
                && $data['command'][0] === '/bin/sh'
                && $data['command'][1] === '-c'
                && str_contains((string) $data['command'][2], 'bash -l');
        });

        $this->assertSame('op-uuid-vm-exec', $session['operation_id']);
        $this->assertSame('exec', $session['driver']);
    }

    public function test_incus_driver_rejects_vga_on_container_without_http_call(): void
    {
        Http::fake();

        $driver = new IncusDriver($this->incusHost);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Containers do not support graphical VGA console.');

        $driver->createConsoleSession($this->container, 'vga');
        Http::assertNothingSent();
    }

    public function test_incus_driver_cancel_operation_sends_delete_request(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/operations/op-cancel-123*' => Http::response([
                'type' => 'sync',
                'status' => 'Success',
                'status_code' => 200,
            ], 200),
            'https://incus-console.local:8443/1.0/operations/op-already-closed*' => Http::response([
                'error' => 'Operation not found',
                'error_code' => 404,
            ], 404),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $success = $driver->cancelOperation($this->vm, 'op-cancel-123');
        $this->assertTrue($success);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), '/1.0/operations/op-cancel-123')
                && str_contains($request->url(), 'project=tenant-console-proj');
        });

        // 404 Not Found is also treated as successfully closed
        $success404 = $driver->cancelOperation($this->vm, 'op-already-closed');
        $this->assertTrue($success404);

        // Blank operation ID returns false without sending HTTP request
        $emptyResult = $driver->cancelOperation($this->vm, '   ');
        $this->assertFalse($emptyResult);
    }

    public function test_incus_driver_throws_on_failure_response(): void
    {
        Http::fake([
            'https://incus-console.local:8443/1.0/instances/vm-console-01/console*' => Http::response([
                'error' => 'Instance is not running',
                'error_code' => 400,
            ], 400),
        ]);

        $driver = new IncusDriver($this->incusHost);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to create console session on hypervisor: Instance is not running');

        $driver->createConsoleSession($this->vm, 'console');
    }

    public function test_exec_driver_delegates_to_hypervisor_and_returns_exec_driver_metadata(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $execDriver = new ExecDriver(app(HypervisorManager::class));
        $this->assertSame('exec', $execDriver->getName());

        $session = $execDriver->open($this->vm, 110, 32);

        $this->assertSame('exec', $session['driver']);
        $this->assertSame('fake-op-exec-'.$this->vm->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-exec-data-'.$this->vm->id, $session['websocket_path']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Exec terminal driver does not support graphical VGA display.');
        $execDriver->open($this->vm, 80, 24, 'vga');
    }

    public function test_console_driver_delegates_to_hypervisor_and_returns_console_driver_metadata(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $consoleDriver = new ConsoleDriver(app(HypervisorManager::class));
        $this->assertSame('console', $consoleDriver->getName());

        $session = $consoleDriver->open($this->vm, 110, 32, 'console');

        $this->assertSame('console', $session['driver']);
        $this->assertSame('fake-op-console-'.$this->vm->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-data-'.$this->vm->id, $session['websocket_path']);
    }
}
