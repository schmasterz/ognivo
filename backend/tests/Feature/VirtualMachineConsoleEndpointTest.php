<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Support\FakeRedis;
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

    private FakeRedis $fakeRedis;

    protected function setUp(): void
    {
        parent::setUp();

        HypervisorManager::resetFake();

        $this->fakeRedis = new FakeRedis;
        Redis::swap($this->fakeRedis);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->withoutMiddleware(ThrottleRequests::class);

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

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        $this->fakeRedis->flush();

        parent::tearDown();
    }

    public function test_owner_can_request_console_session_and_host_url_is_never_returned(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'virtual_machine_id',
            'ticket',
            'websocket_path',
            'expires_at',
            'type',
            'driver',
            'session_id',
            'fallback_reason',
            'idle_timeout',
        ]);

        $json = $response->json();
        $this->assertSame($this->vm->id, $json['virtual_machine_id']);
        $this->assertSame('console', $json['type']);
        $this->assertSame('/console', $json['websocket_path']);
        $this->assertStringNotContainsString($json['ticket'], $json['websocket_path']);
        $this->assertSame('exec', $json['driver']);
        $this->assertNotEmpty($json['session_id']);
        $this->assertNull($json['fallback_reason']);
        $this->assertGreaterThan(0, $json['idle_timeout']);

        // Assert strictly that hypervisor internals never reach the response
        $this->assertArrayNotHasKey('host_endpoint', $json);
        $this->assertArrayNotHasKey('endpoint_url', $json);
        $this->assertArrayNotHasKey('server_public_key_pin', $json);
        $this->assertArrayNotHasKey('server_fingerprint', $json);
        $this->assertArrayNotHasKey('secrets', $json);
        $this->assertStringNotContainsString('incus-secret.local', (string) $response->content());
        $this->assertStringNotContainsString('supersecretpin', (string) $response->content());
    }

    public function test_container_opens_exec_session_and_records_audit_event(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->container->id}/console");

        $response->assertStatus(200);
        $response->assertJson([
            'virtual_machine_id' => $this->container->id,
            'websocket_path' => '/console',
            'driver' => 'exec',
            'fallback_reason' => null,
            'type' => 'console',
        ]);

        $log = AuditLog::where('action', AuditAction::TerminalSessionOpened->value)
            ->where('subject_id', (string) $this->container->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Terminal session opened must create an audit log entry');
        $this->assertSame(AuditOutcome::Success->value, $log->outcome);
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame($this->container->getMorphClass(), $log->subject_type);
        $this->assertSame((string) $this->container->id, $log->subject_id);
        $this->assertSame($this->owner->id, $log->tenant_user_id);
        $this->assertSame('tenant', $log->visibility);
        $this->assertSame('exec', $log->context['driver'] ?? null);
        $this->assertSame($this->container->id, $log->context['virtual_machine_id'] ?? null);
        $this->assertSame($response->json('session_id'), $log->context['session_id'] ?? null);

        // Verify zero terminal content is stored in audit context
        $this->assertArrayNotHasKey('content', $log->context);
        $this->assertArrayNotHasKey('data', $log->context);
        $this->assertArrayNotHasKey('output', $log->context);
        $this->assertArrayNotHasKey('input', $log->context);
    }

    public function test_vm_without_guest_agent_falls_back_to_console_driver(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => false]);
        HypervisorManager::fake($fakeDriver);

        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(200);
        $response->assertJson([
            'virtual_machine_id' => $this->vm->id,
            'websocket_path' => '/console',
            'driver' => 'console',
            'fallback_reason' => 'agent_unavailable',
            'type' => 'console',
        ]);

        $log = AuditLog::where('action', AuditAction::TerminalSessionOpened->value)
            ->where('subject_id', (string) $this->vm->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('console', $log->context['driver'] ?? null);
        $this->assertSame('agent_unavailable', $log->context['fallback_reason'] ?? null);
        $this->assertSame($response->json('session_id'), $log->context['session_id'] ?? null);
        $this->assertArrayNotHasKey('content', $log->context);
    }

    public function test_delete_console_session_closes_session_and_records_audit(): void
    {
        Sanctum::actingAs($this->owner);

        // Open an active session
        $openResponse = $this->postJson("/api/v1/vms/{$this->vm->id}/console");
        $openResponse->assertStatus(200);
        $sessionId = (string) $openResponse->json('session_id');
        $this->assertNotEmpty($sessionId);

        // Close the active session
        $deleteResponse = $this->deleteJson("/api/v1/vms/{$this->vm->id}/console/{$sessionId}", [
            'reason' => 'user_closed_terminal',
        ]);

        $deleteResponse->assertStatus(200);
        $deleteResponse->assertJson(['closed' => true]);

        $closedLogs = AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
            ->where('context->session_id', $sessionId)
            ->get();

        $this->assertCount(1, $closedLogs, 'Must record exactly 1 terminal.session.closed audit log entry');
        $log = $closedLogs->first();
        $this->assertSame(AuditOutcome::Success->value, $log->outcome);
        $this->assertSame($this->owner->id, $log->actor_id);
        $this->assertSame((string) $this->vm->id, $log->subject_id);
        $this->assertSame('exec', $log->context['driver'] ?? null);
        $this->assertGreaterThanOrEqual(0, $log->context['duration_seconds'] ?? -1);
        $this->assertSame('user_closed_terminal', $log->context['reason'] ?? null);

        // Verify zero terminal content is stored in audit context
        $this->assertArrayNotHasKey('content', $log->context);
        $this->assertArrayNotHasKey('data', $log->context);
        $this->assertArrayNotHasKey('output', $log->context);
        $this->assertArrayNotHasKey('input', $log->context);

        // Calling DELETE a second time is idempotent and does not duplicate audit log entry
        $deleteResponse2 = $this->deleteJson("/api/v1/vms/{$this->vm->id}/console/{$sessionId}");
        $deleteResponse2->assertStatus(200);
        $deleteResponse2->assertJson(['closed' => false]);

        $this->assertSame(
            1,
            AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
                ->where('context->session_id', $sessionId)
                ->count()
        );
    }

    public function test_boot_log_works_for_vms_and_containers_with_or_without_agent_and_while_sessions_open(): void
    {
        Sanctum::actingAs($this->owner);

        // 1. Boot log succeeds for VM
        $vmLog = $this->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLog->assertStatus(200);
        $this->assertNotEmpty($vmLog->json('output'));

        // 2. Boot log succeeds for Container
        $ctLog = $this->getJson("/api/v1/vms/{$this->container->id}/console-log");
        $ctLog->assertStatus(200);
        $this->assertNotEmpty($ctLog->json('output'));

        // 3. Open exec session on VM, boot log still succeeds
        $this->postJson("/api/v1/vms/{$this->vm->id}/console")->assertStatus(200);
        $vmLogDuringSession = $this->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLogDuringSession->assertStatus(200);
        $this->assertNotEmpty($vmLogDuringSession->json('output'));

        // 4. Open exec session on Container, boot log still succeeds
        $this->postJson("/api/v1/vms/{$this->container->id}/console")->assertStatus(200);
        $ctLogDuringSession = $this->getJson("/api/v1/vms/{$this->container->id}/console-log");
        $ctLogDuringSession->assertStatus(200);
        $this->assertNotEmpty($ctLogDuringSession->json('output'));

        // 5. When VM guest agent is unavailable (fallback to console), boot log still succeeds
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => false]);
        HypervisorManager::fake($fakeDriver);

        // Clean previous sessions on VM so we can open a fallback console session
        Redis::del('console:vm_sessions:'.$this->vm->id);

        $fallbackRes = $this->postJson("/api/v1/vms/{$this->vm->id}/console");
        $fallbackRes->assertStatus(200);
        $this->assertSame('console', $fallbackRes->json('driver'));

        $vmLogFallback = $this->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLogFallback->assertStatus(200);
        $this->assertNotEmpty($vmLogFallback->json('output'));
    }

    public function test_other_user_is_forbidden_from_requesting_console(): void
    {
        Sanctum::actingAs($this->intruder);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
    }

    public function test_admin_is_strictly_forbidden_from_requesting_console(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
        $this->assertStringContainsString('Console access is restricted to the workload owner only', (string) $response->json('message'));
    }

    public function test_admin_can_request_console_session_for_their_own_vm(): void
    {
        $adminVm = VirtualMachine::create([
            'user_id' => $this->admin->id,
            'host_id' => $this->host->id,
            'name' => 'admin-owned-vm',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->postJson("/api/v1/vms/{$adminVm->id}/console");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'virtual_machine_id',
            'ticket',
            'websocket_path',
            'expires_at',
            'type',
            'driver',
            'session_id',
            'fallback_reason',
            'idle_timeout',
        ]);
        $this->assertSame($adminVm->id, $response->json('virtual_machine_id'));
    }

    public function test_stopped_vm_returns_409_conflict(): void
    {
        $this->vm->status->transitionTo(Stopped::class);
        $this->vm->save();

        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'Interactive console is only available while the instance is running.',
        ]);
    }

    public function test_container_rejects_vga_type(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->container->id}/console", [
            'type' => 'vga',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Containers do not support graphical VGA console.',
        ]);
    }

    public function test_legacy_console_ticket_route_still_works(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/v1/vms/{$this->vm->id}/console-ticket");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'virtual_machine_id',
            'ticket',
        ]);
    }

    public function test_console_log_endpoint_records_audit_log_entry(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/v1/vms/{$this->vm->id}/console-log");

        $response->assertStatus(200);

        $log = AuditLog::where('action', AuditAction::VmActionRequested->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Console log view must create an audit log entry');
        $this->assertSame(AuditOutcome::Success->value, $log->outcome);
        $this->assertSame($this->vm->getMorphClass(), $log->subject_type);
        $this->assertSame((string) $this->vm->id, $log->subject_id);
        $this->assertSame($this->owner->id, $log->tenant_user_id);
        $this->assertSame('tenant', $log->visibility);
        $this->assertSame('console_log.viewed', $log->context['action'] ?? null);
        $this->assertSame($this->vm->id, $log->context['virtual_machine_id'] ?? null);
        $this->assertGreaterThan(0, $log->context['bytes_returned'] ?? 0);
    }

    public function test_console_post_is_forbidden_and_audited_while_impersonating(): void
    {
        $token = $this->owner->createToken(
            name: 'impersonation:'.$this->admin->id,
            abilities: ['impersonated', 'impersonator:'.$this->admin->id],
        )->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Issuing console tickets is forbidden while impersonating.']);

        $log = AuditLog::where('action', AuditAction::VmActionDenied->value)
            ->where('outcome', AuditOutcome::Denied->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Denied console request must create an audit log entry');
        $this->assertSame($this->admin->id, $log->impersonator_id);
        $this->assertSame($this->owner->id, $log->tenant_user_id);
        $this->assertSame('tenant', $log->visibility);
        $this->assertSame('console.ticket', $log->context['action'] ?? null);
        $this->assertSame('forbidden_while_impersonating', $log->context['reason'] ?? null);

        // Verify zero terminal content is stored
        $this->assertArrayNotHasKey('content', $log->context);
        $this->assertArrayNotHasKey('data', $log->context);
    }

    public function test_owner_can_request_console_session_with_sanctum_bearer_token(): void
    {
        $token = $this->owner->createToken('auth-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'ticket',
            'websocket_path',
            'expires_at',
            'type',
            'driver',
            'session_id',
            'fallback_reason',
            'idle_timeout',
        ]);
        $this->assertSame('/console', $response->json('websocket_path'));
        $this->assertStringNotContainsString($response->json('ticket'), $response->json('websocket_path'));
    }
}
