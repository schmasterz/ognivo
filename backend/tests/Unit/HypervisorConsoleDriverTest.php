<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Host;
use App\Models\PanelCertificate;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\Drivers\IncusDriver;
use App\Services\Hypervisor\HypervisorGateway;
use App\Services\PanelCertificateService;
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

    private PanelCertificate $cert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'project_name' => 'tenant-console-proj',
        ]);

        $this->cert = app(PanelCertificateService::class)->generate(365);

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

    public function test_fake_driver_creates_console_session_for_vm(): void
    {
        $fakeDriver = new FakeHypervisorDriver();
        $session = $fakeDriver->createConsoleSession($this->vm, 'console');

        $this->assertSame('fake-op-console-'.$this->vm->id, $session['operation_id']);
        $this->assertStringContainsString('fake-sec-data-'.$this->vm->id, $session['websocket_path']);
        $this->assertNotNull($session['control_path']);
        $this->assertSame('console', $session['type']);
    }

    public function test_fake_driver_creates_console_session_for_container(): void
    {
        $fakeDriver = new FakeHypervisorDriver();
        $session = $fakeDriver->createConsoleSession($this->container, 'console');

        $this->assertSame('fake-op-console-'.$this->container->id, $session['operation_id']);
        $this->assertSame('console', $session['type']);
    }

    public function test_fake_driver_rejects_vga_on_container(): void
    {
        $fakeDriver = new FakeHypervisorDriver();

        $this->expectException(InvalidArgumentException::class);
        $fakeDriver->createConsoleSession($this->container, 'vga');
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

        $session = $driver->createConsoleSession($this->vm, 'console');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/1.0/instances/vm-console-01/console')
                && $request['type'] === 'console'
                && str_contains($request->url(), 'project=tenant-console-proj')
                && str_contains($request->url(), 'wait-for-websocket=true');
        });

        $this->assertSame('op-uuid-12345', $session['operation_id']);
        $this->assertSame('/1.0/operations/op-uuid-12345/websocket?secret=secret-data-token', $session['websocket_path']);
        $this->assertSame('/1.0/operations/op-uuid-12345/websocket?secret=secret-ctrl-token', $session['control_path']);
        $this->assertSame('console', $session['type']);
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
}
