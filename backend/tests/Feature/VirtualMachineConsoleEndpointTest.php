<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class VirtualMachineConsoleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $intruder;

    private User $admin;

    private Host $host;

    private VirtualMachine $vm;

    private VirtualMachine $container;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->owner = User::factory()->create([
            'status' => 'active',
        ]);
        $this->owner->assignRole('customer');

        $this->intruder = User::factory()->create([
            'status' => 'active',
        ]);
        $this->intruder->assignRole('customer');

        $this->admin = User::factory()->create([
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'console-api-host',
            'endpoint_url' => 'https://incus-secret.local:8443',
            'driver' => 'fake',
            'server_public_key_pin' => 'sha256//supersecretpin',
            'server_fingerprint' => 'secretfingerprint',
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'api-console-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $this->container = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'api-console-ct',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);
    }

    public function test_owner_can_request_console_session_and_host_url_is_never_returned(): void
    {
        Redis::spy();
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/vms/{$this->vm->id}/console");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'virtual_machine_id',
            'ticket',
            'websocket_path',
            'expires_at',
            'type',
        ]);

        $json = $response->json();
        $this->assertSame($this->vm->id, $json['virtual_machine_id']);
        $this->assertSame('console', $json['type']);
        $this->assertStringStartsWith('/console?ticket=', $json['websocket_path']);

        // Assert strictly that hypervisor internals never reach the response
        $this->assertArrayNotHasKey('host_endpoint', $json);
        $this->assertArrayNotHasKey('endpoint_url', $json);
        $this->assertArrayNotHasKey('server_public_key_pin', $json);
        $this->assertArrayNotHasKey('server_fingerprint', $json);
        $this->assertArrayNotHasKey('secrets', $json);
        $this->assertStringNotContainsString('incus-secret.local', $response->content());
        $this->assertStringNotContainsString('supersecretpin', $response->content());
    }

    public function test_other_user_is_forbidden_from_requesting_console(): void
    {
        Sanctum::actingAs($this->intruder);

        $response = $this->postJson("/api/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
    }

    public function test_admin_is_strictly_forbidden_from_requesting_console(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
        $this->assertStringContainsString('Console access is restricted to the workload owner only', (string) $response->json('message'));
    }

    public function test_stopped_vm_returns_409_conflict(): void
    {
        $this->vm->status->transitionTo(Stopped::class);
        $this->vm->save();

        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/vms/{$this->vm->id}/console");

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'Interactive console is only available while the instance is running.',
        ]);
    }

    public function test_container_rejects_vga_type(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/vms/{$this->container->id}/console", [
            'type' => 'vga',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Containers do not support graphical VGA console.',
        ]);
    }

    public function test_legacy_console_ticket_route_still_works(): void
    {
        Redis::spy();
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/vms/{$this->vm->id}/console-ticket");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'virtual_machine_id',
            'ticket',
        ]);
    }
}
