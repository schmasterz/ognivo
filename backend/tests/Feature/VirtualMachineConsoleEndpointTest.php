<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Audit\AuditAction;
use App\Audit\AuditOutcome;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ConsoleTicketService;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;
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

        // Enforce CSRF verification during tests so missing/invalid X-XSRF-TOKEN returns 419.
        $this->app->bind(ValidateCsrfToken::class, static fn (Application $app): ValidateCsrfToken => new class($app, $app->make(Encrypter::class)) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });

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

    /**
     * Establish a stateful browser cookie session and return stateful CSRF request headers.
     *
     * @return array<string, string>
     */
    private function actingAsStatefulUser(User $user, string $password = 'password'): array
    {
        $csrfRes = $this->withCredentials()
            ->withHeaders(['Origin' => 'http://localhost'])
            ->getJson('/sanctum/csrf-cookie');
        $csrfRes->assertNoContent();

        $xsrfCookie = (string) ($csrfRes->getCookie('XSRF-TOKEN', false)?->getValue() ?? '');
        $sessionCookieName = (string) config('session.cookie');
        $sessionCookie = (string) ($csrfRes->getCookie($sessionCookieName, false)?->getValue() ?? '');

        $loginRes = $this->withCredentials()
            ->withUnencryptedCookies([
                'XSRF-TOKEN' => $xsrfCookie,
                $sessionCookieName => $sessionCookie,
            ])
            ->withHeaders([
                'Origin' => 'http://localhost',
                'X-XSRF-TOKEN' => $xsrfCookie,
            ])
            ->postJson('/api/v1/login', [
                'email' => $user->email,
                'password' => $password,
            ]);
        $loginRes->assertOk();

        return $this->syncStatefulCookies($loginRes, [
            'Origin' => 'http://localhost',
            'Accept' => 'application/json',
            'X-XSRF-TOKEN' => $xsrfCookie,
        ], $sessionCookie);
    }

    /**
     * Sync updated session and XSRF cookies from a stateful response into the test client.
     *
     * @param  TestResponse<Response>  $response
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function syncStatefulCookies(TestResponse $response, array $headers, string $fallbackSessionCookie = ''): array
    {
        $sessionCookieName = (string) config('session.cookie');
        $newSessionCookie = $response->getCookie($sessionCookieName, false)?->getValue();
        $sessionCookie = is_string($newSessionCookie) && $newSessionCookie !== ''
            ? $newSessionCookie
            : $fallbackSessionCookie;

        $xsrfCookie = $headers['X-XSRF-TOKEN'] ?? '';
        $newXsrfCookie = $response->getCookie('XSRF-TOKEN', false)?->getValue();
        if (is_string($newXsrfCookie) && $newXsrfCookie !== '') {
            $xsrfCookie = $newXsrfCookie;
            $headers['X-XSRF-TOKEN'] = $xsrfCookie;
        }

        $cookies = ['XSRF-TOKEN' => $xsrfCookie];
        if ($sessionCookie !== '') {
            $cookies[$sessionCookieName] = $sessionCookie;
        }

        $this->withCredentials()->withUnencryptedCookies($cookies);
        $this->app['auth']->forgetGuards();

        return $headers;
    }

    public function test_owner_can_request_console_session_and_host_url_is_never_returned(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

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
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->container->id}/console");

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

        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

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
        $headers = $this->actingAsStatefulUser($this->owner);

        // Open an active session
        $openResponse = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");
        $openResponse->assertStatus(200);
        $sessionId = (string) $openResponse->json('session_id');
        $this->assertNotEmpty($sessionId);

        // Close the active session
        $deleteResponse = $this->withHeaders($headers)
            ->deleteJson("/api/v1/vms/{$this->vm->id}/console/{$sessionId}", [
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
        $deleteResponse2 = $this->withHeaders($headers)
            ->deleteJson("/api/v1/vms/{$this->vm->id}/console/{$sessionId}");
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
        $headers = $this->actingAsStatefulUser($this->owner);

        // 1. Boot log succeeds for VM
        $vmLog = $this->withHeaders($headers)->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLog->assertStatus(200);
        $this->assertNotEmpty($vmLog->json('output'));

        // 2. Boot log succeeds for Container
        $ctLog = $this->withHeaders($headers)->getJson("/api/v1/vms/{$this->container->id}/console-log");
        $ctLog->assertStatus(200);
        $this->assertNotEmpty($ctLog->json('output'));

        // 3. Open exec session on VM, boot log still succeeds
        $this->withHeaders($headers)->postJson("/api/v1/vms/{$this->vm->id}/console")->assertStatus(200);
        $vmLogDuringSession = $this->withHeaders($headers)->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLogDuringSession->assertStatus(200);
        $this->assertNotEmpty($vmLogDuringSession->json('output'));

        // 4. Open exec session on Container, boot log still succeeds
        $this->withHeaders($headers)->postJson("/api/v1/vms/{$this->container->id}/console")->assertStatus(200);
        $ctLogDuringSession = $this->withHeaders($headers)->getJson("/api/v1/vms/{$this->container->id}/console-log");
        $ctLogDuringSession->assertStatus(200);
        $this->assertNotEmpty($ctLogDuringSession->json('output'));

        // 5. When VM guest agent is unavailable (fallback to console), boot log still succeeds
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => false]);
        HypervisorManager::fake($fakeDriver);

        // Clean previous sessions on VM so we can open a fallback console session
        Redis::del('console:vm_sessions:'.$this->vm->id);

        $fallbackRes = $this->withHeaders($headers)->postJson("/api/v1/vms/{$this->vm->id}/console");
        $fallbackRes->assertStatus(200);
        $this->assertSame('console', $fallbackRes->json('driver'));

        $vmLogFallback = $this->withHeaders($headers)->getJson("/api/v1/vms/{$this->vm->id}/console-log");
        $vmLogFallback->assertStatus(200);
        $this->assertNotEmpty($vmLogFallback->json('output'));
    }

    public function test_other_user_is_forbidden_from_requesting_console(): void
    {
        $headers = $this->actingAsStatefulUser($this->intruder);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(403);
    }

    public function test_admin_is_strictly_forbidden_from_requesting_console(): void
    {
        $headers = $this->actingAsStatefulUser($this->admin);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

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

        $headers = $this->actingAsStatefulUser($this->admin);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$adminVm->id}/console");

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

        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(409);
        $response->assertJsonFragment([
            'message' => 'Interactive console is only available while the instance is running.',
        ]);
    }

    public function test_container_rejects_vga_type(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->container->id}/console", [
                'type' => 'vga',
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Containers do not support graphical VGA console.',
        ]);
    }

    public function test_deprecated_console_ticket_route_is_removed(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console-ticket");

        $this->assertContains($response->status(), [404, 405]);
    }

    public function test_console_log_endpoint_records_audit_log_entry(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->getJson("/api/v1/vms/{$this->vm->id}/console-log");

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
        $adminHeaders = $this->actingAsStatefulUser($this->admin);

        $impersonateRes = $this->withHeaders($adminHeaders)
            ->postJson("/api/v1/admin/users/{$this->owner->id}/impersonate");
        $impersonateRes->assertOk();

        $impersonatedHeaders = $this->syncStatefulCookies($impersonateRes, $adminHeaders);

        $response = $this->withHeaders($impersonatedHeaders)
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

    public function test_ticket_reuse_is_rejected(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");
        $response->assertStatus(200);

        $ticket = (string) $response->json('ticket');
        $this->assertNotEmpty($ticket);

        $service = app(ConsoleTicketService::class);
        $firstRedeem = $service->redeemTicket($ticket);
        $this->assertIsArray($firstRedeem);
        $this->assertSame($this->vm->id, $firstRedeem['virtual_machine_id']);

        $secondRedeem = $service->redeemTicket($ticket);
        $this->assertNull($secondRedeem);
    }

    public function test_expired_ticket_and_past_session_expires_at_are_rejected(): void
    {
        $service = app(ConsoleTicketService::class);

        $pastSessionDto = $service->issueTicket(
            $this->vm,
            $this->owner,
            sessionExpiresAt: time() - 10,
        );
        $this->assertNull($service->redeemTicket($pastSessionDto->ticket));

        $missingSessionExpiryDto = $service->issueTicket(
            $this->vm,
            $this->owner,
            sessionExpiresAt: null,
        );
        $this->assertNull($service->redeemTicket($missingSessionExpiryDto->ticket));
    }

    public function test_ticket_is_bound_to_requested_virtual_machine_id(): void
    {
        $otherVm = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'api-console-vm-b',
            'incus_project' => 'default',
            'instance_type' => 'virtual-machine',
            'status' => Running::class,
            'cpu_cores' => 2,
            'ram_mb' => 2048,
            'disk_gb' => 20,
            'os_image' => 'images:ubuntu/24.04',
        ]);

        $headers = $this->actingAsStatefulUser($this->owner);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");
        $response->assertStatus(200);

        $payload = app(ConsoleTicketService::class)->redeemTicket((string) $response->json('ticket'));
        $this->assertIsArray($payload);
        $this->assertSame($this->vm->id, $payload['virtual_machine_id']);
        $this->assertNotSame($otherVm->id, $payload['virtual_machine_id']);
    }

    public function test_issuance_without_session_returns_401(): void
    {
        // Unauthenticated stateless request
        $this->postJson("/api/v1/vms/{$this->vm->id}/console")
            ->assertStatus(401);

        // Bearer token is disabled and has no session
        $token = $this->owner->createToken('auth-token')->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/vms/{$this->vm->id}/console")
            ->assertStatus(401);

        // Authenticated guard without an HTTP session
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/v1/vms/{$this->vm->id}/console")
            ->assertStatus(401)
            ->assertJson(['message' => 'Session required.']);
    }

    public function test_issuance_with_session_but_without_xsrf_token_returns_419(): void
    {
        $headers = $this->actingAsStatefulUser($this->owner);
        unset($headers['X-XSRF-TOKEN']);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");

        $response->assertStatus(419);
    }

    public function test_ticket_payload_contains_session_expires_at_matching_login_at_and_absolute_lifetime(): void
    {
        config(['session.absolute_lifetime' => 120]);

        $headers = $this->actingAsStatefulUser($this->owner);
        $loginAt = app('session.store')->get('login_at');
        $this->assertIsInt($loginAt);

        $response = $this->withHeaders($headers)
            ->postJson("/api/v1/vms/{$this->vm->id}/console");
        $response->assertStatus(200);

        $payload = app(ConsoleTicketService::class)->redeemTicket((string) $response->json('ticket'));
        $this->assertIsArray($payload);
        $this->assertSame($loginAt + (120 * 60), $payload['session_expires_at'] ?? null);
    }
}
