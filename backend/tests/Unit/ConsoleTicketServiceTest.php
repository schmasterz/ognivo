<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Models\AuditLog;
use App\Models\Host;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\ConsoleTicketService;
use App\Services\Hypervisor\Drivers\FakeHypervisorDriver;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\PanelCertificateService;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\FakeRedis;
use Tests\TestCase;

final class ConsoleTicketServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherUser;

    private User $admin;

    private Host $host;

    private VirtualMachine $vm;

    private VirtualMachine $container;

    private ConsoleTicketService $service;

    private FakeRedis $fakeRedis;

    protected function setUp(): void
    {
        parent::setUp();

        HypervisorManager::resetFake();

        $this->fakeRedis = new FakeRedis;
        Redis::swap($this->fakeRedis);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

        $this->owner = User::factory()->create([
            'status' => 'active',
        ]);
        $this->owner->assignRole('customer');

        $this->otherUser = User::factory()->create([
            'status' => 'active',
        ]);
        $this->otherUser->assignRole('customer');

        $this->admin = User::factory()->create([
            'status' => 'active',
        ]);
        $this->admin->assignRole('admin');

        $this->host = Host::create([
            'name' => 'fake-console-host',
            'endpoint_url' => 'https://fake-node.local:8443',
            'driver' => 'fake',
            'is_active' => true,
        ]);

        $this->vm = VirtualMachine::create([
            'user_id' => $this->owner->id,
            'host_id' => $this->host->id,
            'name' => 'console-test-vm',
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
            'name' => 'console-test-ct',
            'incus_project' => 'default',
            'instance_type' => 'container',
            'status' => Running::class,
            'cpu_cores' => 1,
            'ram_mb' => 1024,
            'disk_gb' => 10,
            'os_image' => 'images:alpine/3.20',
        ]);

        $hypervisorManager = app(HypervisorManager::class);
        $this->service = new ConsoleTicketService($hypervisorManager);
    }

    protected function tearDown(): void
    {
        HypervisorManager::resetFake();
        $this->fakeRedis->flush();

        parent::tearDown();
    }

    public function test_issue_ticket_stores_payload_in_redis_and_returns_safe_dto(): void
    {
        $dto = $this->service->issueTicket($this->vm, $this->owner, 'console');

        $this->assertSame($this->vm->id, $dto->virtualMachineId);
        $this->assertSame(64, strlen($dto->ticket));
        $this->assertSame('/console', $dto->websocketPath);
        $this->assertStringNotContainsString($dto->ticket, $dto->websocketPath);
        $this->assertNotEmpty($dto->expiresAt);
        $this->assertSame('console', $dto->type);
        $this->assertSame('exec', $dto->driver);
        $this->assertNotEmpty($dto->sessionId);
        $this->assertNull($dto->fallbackReason);
        $this->assertGreaterThan(0, $dto->idleTimeout);

        $rawPayload = $this->fakeRedis->get('console:ticket:'.$dto->ticket);
        $this->assertNotNull($rawPayload);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $rawPayload);
        $this->assertStringNotContainsString('client_private_key', $rawPayload);

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($rawPayload, true);
        $this->assertSame(1, $envelope['v'] ?? null);
        $this->assertSame('AES-256-GCM', $envelope['alg'] ?? null);
        $this->assertIsString($envelope['iv'] ?? null);
        $this->assertIsString($envelope['tag'] ?? null);
        $this->assertIsString($envelope['ct'] ?? null);

        $data = $this->service->decryptTicketPayload($rawPayload, $dto->ticket);
        $this->assertIsArray($data);
        $this->assertSame($this->owner->id, $data['user_id']);
        $this->assertSame($this->vm->id, $data['virtual_machine_id']);
        $this->assertSame('console', $data['console_type']);
        $this->assertSame('exec', $data['driver']);
        $this->assertSame($dto->sessionId, $data['session_id']);
        $this->assertIsString($data['websocket_path']);
        $this->assertStringContainsString('fake-op-exec-', $data['websocket_path']);
        $this->assertNull($data['client_certificate']);
        $this->assertNull($data['client_private_key']);
    }

    public function test_issue_ticket_stores_mtls_credentials_when_panel_certificate_exists(): void
    {
        $certService = app(PanelCertificateService::class);
        $panelCert = $certService->generate();

        $dto = $this->service->issueTicket($this->vm, $this->owner, 'console');

        $this->assertSame($this->vm->id, $dto->virtualMachineId);
        $this->assertSame(64, strlen($dto->ticket));
        $this->assertSame('/console', $dto->websocketPath);
        $this->assertStringNotContainsString($dto->ticket, $dto->websocketPath);
        $this->assertSame('exec', $dto->driver);
        $this->assertNotEmpty($dto->sessionId);

        $rawPayload = $this->fakeRedis->get('console:ticket:'.$dto->ticket);
        $this->assertNotNull($rawPayload);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $rawPayload);
        $this->assertStringNotContainsString('client_private_key', $rawPayload);

        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($rawPayload, true);
        $this->assertSame(1, $envelope['v'] ?? null);
        $this->assertSame('AES-256-GCM', $envelope['alg'] ?? null);
        $this->assertIsString($envelope['iv'] ?? null);
        $this->assertIsString($envelope['tag'] ?? null);
        $this->assertIsString($envelope['ct'] ?? null);

        $data = $this->service->decryptTicketPayload($rawPayload, $dto->ticket);
        $this->assertIsArray($data);
        $this->assertSame($this->owner->id, $data['user_id']);
        $this->assertSame($this->vm->id, $data['virtual_machine_id']);
        $this->assertSame('console', $data['console_type']);
        $this->assertSame('exec', $data['driver']);
        $this->assertSame($dto->sessionId, $data['session_id']);
        $this->assertIsString($data['websocket_path']);
        $this->assertStringContainsString('fake-op-exec-', $data['websocket_path']);
        $this->assertSame($panelCert->certificate, $data['client_certificate']);
        $this->assertSame($panelCert->private_key, $data['client_private_key']);
    }

    public function test_container_issues_exec_session_by_default(): void
    {
        $dto = $this->service->issueTicket($this->container, $this->owner);

        $this->assertSame('exec', $dto->driver);
        $this->assertNull($dto->fallbackReason);
        $this->assertSame('/console', $dto->websocketPath);
        $this->assertStringNotContainsString($dto->ticket, $dto->websocketPath);
        $this->assertNotEmpty($dto->sessionId);
    }

    public function test_vm_with_running_agent_issues_exec_session(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => true]);
        HypervisorManager::fake($fakeDriver);

        $dto = $this->service->issueTicket($this->vm, $this->owner);

        $this->assertSame('exec', $dto->driver);
        $this->assertNull($dto->fallbackReason);
        $this->assertSame('/console', $dto->websocketPath);
        $this->assertNotEmpty($dto->sessionId);
    }

    public function test_vm_without_guest_agent_falls_back_to_console_driver(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        $fakeDriver->setInstanceState($this->vm, ['agent_running' => false]);
        HypervisorManager::fake($fakeDriver);

        $dto = $this->service->issueTicket($this->vm, $this->owner);

        $this->assertSame('console', $dto->driver);
        $this->assertSame('agent_unavailable', $dto->fallbackReason);
        $this->assertSame('/console', $dto->websocketPath);
        $this->assertNotEmpty($dto->sessionId);
    }

    public function test_concurrency_limit_on_console_driver_enforces_single_session_and_closing_releases_slot(): void
    {
        // Issue first serial console session
        $dto1 = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');
        $this->assertSame('console', $dto1->driver);

        // Attempting to issue a second serial console session throws 409 Conflict
        try {
            $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');
            $this->fail('Expected HttpException(409) when serial console is already active');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('A serial console session is already active for this instance.', $e->getMessage());
        }

        // Closing the active session releases the slot
        $closed = $this->service->closeSession($this->vm, $dto1->sessionId, $this->owner);
        $this->assertTrue($closed);

        // A second serial console session can now be opened successfully
        $dto2 = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');
        $this->assertSame('console', $dto2->driver);
        $this->assertNotSame($dto1->sessionId, $dto2->sessionId);
    }

    public function test_concurrency_limit_on_exec_driver_enforces_max_sessions_and_closing_releases_slot(): void
    {
        config(['hypervisor.terminal.max_exec_sessions_per_instance' => 2]);

        $dto1 = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'exec');
        $dto2 = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'exec');

        $this->assertSame('exec', $dto1->driver);
        $this->assertSame('exec', $dto2->driver);

        // Third session exceeds limit of 2 and throws 429 Too Many Requests
        try {
            $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'exec');
            $this->fail('Expected HttpException(429) when max exec sessions reached');
        } catch (HttpException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertSame('Maximum number of concurrent terminal sessions reached for this instance.', $e->getMessage());
        }

        // Close one session
        $closed = $this->service->closeSession($this->vm, $dto1->sessionId, $this->owner);
        $this->assertTrue($closed);

        // Now third session succeeds
        $dto3 = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'exec');
        $this->assertSame('exec', $dto3->driver);
    }

    public function test_idle_timeout_session_pruning_cancels_upstream_operation_and_frees_slot(): void
    {
        config(['hypervisor.terminal.idle_timeout_seconds' => 300]);

        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $expiredSessionId = 'expired-idle-session-uuid-1';
        $expiredOpId = 'op-upstream-idle-123';
        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);

        $now = microtime(true);
        $expiredRecord = [
            'session_id' => $expiredSessionId,
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->owner->id,
            'driver' => 'console',
            'console_type' => 'console',
            'operation_id' => $expiredOpId,
            'opened_at' => $now - 1000,
            'last_activity_at' => $now - 800,
            'close_token' => 'dummy-close-token',
        ];

        Redis::hset($vmSessionsKey, $expiredSessionId, json_encode($expiredRecord, JSON_THROW_ON_ERROR));
        Redis::hset('console:session_index', $expiredSessionId, (string) $this->vm->id);

        // Requesting a console session would normally conflict (409) if the old session were still active.
        // It must automatically prune the expired session, cancel the upstream operation, and succeed.
        $newDto = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');

        $this->assertSame('console', $newDto->driver);
        $this->assertContains($expiredOpId, $fakeDriver->cancelledOperations);

        // The old session was pruned from Redis
        $this->assertNull(Redis::hget($vmSessionsKey, $expiredSessionId));

        // Audit entry recorded for the pruned idle session
        $prunedLog = AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
            ->where('context->session_id', $expiredSessionId)
            ->first();

        $this->assertNotNull($prunedLog);
        $this->assertSame('idle_timeout', $prunedLog->context['reason'] ?? null);
        $this->assertSame('console', $prunedLog->context['driver'] ?? null);
        $this->assertGreaterThan(0, $prunedLog->context['duration_seconds'] ?? 0);
    }

    public function test_unredeemed_expired_ticket_is_pruned_after_ticket_ttl_and_releases_console_slot(): void
    {
        config([
            'hypervisor.terminal.ticket_ttl_seconds' => 60,
            'hypervisor.terminal.idle_timeout_seconds' => 900,
        ]);

        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $expiredSessionId = 'unredeemed-expired-session-uuid-1';
        $expiredOpId = 'op-unredeemed-123';
        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);

        $now = microtime(true);
        $expiredRecord = [
            'session_id' => $expiredSessionId,
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->owner->id,
            'driver' => 'console',
            'console_type' => 'console',
            'operation_id' => $expiredOpId,
            'opened_at' => $now - 70,
            'last_activity_at' => $now - 70,
            'ticket_expires_at' => $now - 10,
            'redeemed_at' => null,
            'close_token' => 'dummy-close-token',
        ];

        Redis::hset($vmSessionsKey, $expiredSessionId, json_encode($expiredRecord, JSON_THROW_ON_ERROR));
        Redis::hset('console:session_index', $expiredSessionId, (string) $this->vm->id);

        $newDto = $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');

        $this->assertSame('console', $newDto->driver);
        $this->assertContains($expiredOpId, $fakeDriver->cancelledOperations);
        $this->assertNull(Redis::hget($vmSessionsKey, $expiredSessionId));
        $this->assertNull(Redis::hget('console:session_index', $expiredSessionId));

        $prunedLog = AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
            ->where('context->session_id', $expiredSessionId)
            ->first();

        $this->assertNotNull($prunedLog);
        $this->assertSame('ticket_expired', $prunedLog->context['reason'] ?? null);
        $this->assertSame('console', $prunedLog->context['driver'] ?? null);
    }

    public function test_active_session_with_refreshed_last_activity_at_is_not_pruned_past_initial_idle_timeout(): void
    {
        config(['hypervisor.terminal.idle_timeout_seconds' => 300]);

        $activeSessionId = 'active-refreshed-session-uuid-1';
        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);

        $now = microtime(true);
        $activeRecord = [
            'session_id' => $activeSessionId,
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->owner->id,
            'driver' => 'console',
            'console_type' => 'console',
            'operation_id' => 'op-active-123',
            'opened_at' => $now - 1000,
            'last_activity_at' => $now - 20,
            'redeemed_at' => $now - 995,
            'close_token' => 'dummy-close-token',
        ];

        Redis::hset($vmSessionsKey, $activeSessionId, json_encode($activeRecord, JSON_THROW_ON_ERROR));
        Redis::hset('console:session_index', $activeSessionId, (string) $this->vm->id);

        try {
            $this->service->issueTicket($this->vm, $this->owner, 'console', preferredDriver: 'console');
            $this->fail('Expected HttpException(409) when active serial console session has recent activity');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
            $this->assertSame('A serial console session is already active for this instance.', $e->getMessage());
        }

        $this->assertNotNull(Redis::hget($vmSessionsKey, $activeSessionId));
    }

    public function test_close_session_removes_from_redis_cancels_operation_and_records_audit(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $dto = $this->service->issueTicket($this->vm, $this->owner);

        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);
        $this->assertNotNull(Redis::hget($vmSessionsKey, $dto->sessionId));

        $closed = $this->service->closeSession($this->vm, $dto->sessionId, $this->owner, 'user_exit');
        $this->assertTrue($closed);

        // Redis entries removed
        $this->assertNull(Redis::hget($vmSessionsKey, $dto->sessionId));
        $this->assertNull(Redis::hget('console:session_index', $dto->sessionId));

        // Upstream operation cancelled
        $this->assertNotEmpty($fakeDriver->cancelledOperations);

        // Audit entry recorded
        $audit = AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
            ->where('context->session_id', $dto->sessionId)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame($this->owner->id, $audit->actor_id);
        $this->assertSame((string) $this->vm->id, $audit->subject_id);
        $this->assertSame('exec', $audit->context['driver'] ?? null);
        $this->assertGreaterThanOrEqual(0, $audit->context['duration_seconds'] ?? -1);
        $this->assertSame('user_exit', $audit->context['reason'] ?? null);

        // Repeated close returns false (idempotent)
        $closedAgain = $this->service->closeSession($this->vm, $dto->sessionId, $this->owner);
        $this->assertFalse($closedAgain);
    }

    public function test_close_session_by_token_removes_from_redis_cancels_operation_and_records_audit(): void
    {
        $fakeDriver = new FakeHypervisorDriver;
        HypervisorManager::fake($fakeDriver);

        $dto = $this->service->issueTicket($this->vm, $this->owner);

        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);
        $raw = Redis::hget($vmSessionsKey, $dto->sessionId);
        $this->assertNotNull($raw);
        /** @var array<string, mixed> $record */
        $record = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
        $closeToken = (string) $record['close_token'];

        // Reject invalid token
        $invalidResult = $this->service->closeSessionByToken($dto->sessionId, 'wrong-token-abc');
        $this->assertFalse($invalidResult);

        // Accept valid token
        $validResult = $this->service->closeSessionByToken($dto->sessionId, $closeToken, 'websocket_closed');
        $this->assertTrue($validResult);

        $this->assertNull(Redis::hget($vmSessionsKey, $dto->sessionId));
        $this->assertNull(Redis::hget('console:session_index', $dto->sessionId));
        $this->assertNotEmpty($fakeDriver->cancelledOperations);

        $audit = AuditLog::where('action', AuditAction::TerminalSessionClosed->value)
            ->where('context->session_id', $dto->sessionId)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('websocket_closed', $audit->context['reason'] ?? null);
        $this->assertSame('exec', $audit->context['driver'] ?? null);
    }

    public function test_single_use_ticket_redemption(): void
    {
        $ticket = 'test-ticket-single-use-12345';
        $sessionId = 'single-use-session-uuid-1';
        $initialTime = microtime(true) - 5.0;
        $vmSessionsKey = sprintf('console:vm_sessions:%d', $this->vm->id);

        $sessionRecord = [
            'session_id' => $sessionId,
            'virtual_machine_id' => $this->vm->id,
            'user_id' => $this->owner->id,
            'driver' => 'exec',
            'console_type' => 'console',
            'operation_id' => 'op-single-use-1',
            'opened_at' => $initialTime,
            'last_activity_at' => $initialTime,
            'ticket_expires_at' => $initialTime + 60.0,
            'redeemed_at' => null,
            'close_token' => 'dummy-close-token',
        ];
        Redis::hset($vmSessionsKey, $sessionId, json_encode($sessionRecord, JSON_THROW_ON_ERROR));

        $payload = [
            'user_id' => $this->owner->id,
            'virtual_machine_id' => $this->vm->id,
            'console_type' => 'console',
            'session_id' => $sessionId,
            'session_expires_at' => now()->addHour()->timestamp,
        ];

        $this->fakeRedis->setex('console:ticket:'.$ticket, 60, $this->service->encryptTicketPayload($payload, $ticket));

        $result = $this->service->redeemTicket($ticket);
        $this->assertNotNull($result);
        $this->assertSame($this->owner->id, $result['user_id']);

        $rawUpdated = Redis::hget($vmSessionsKey, $sessionId);
        $this->assertIsString($rawUpdated);
        /** @var array<string, mixed> $updatedRecord */
        $updatedRecord = json_decode($rawUpdated, true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotNull($updatedRecord['redeemed_at'] ?? null);
        $this->assertGreaterThan($initialTime, $updatedRecord['redeemed_at']);
        $this->assertNotNull($updatedRecord['last_activity_at'] ?? null);
        $this->assertGreaterThan($initialTime, $updatedRecord['last_activity_at']);

        // Subsequent redemption returns null
        $secondResult = $this->service->redeemTicket($ticket);
        $this->assertNull($secondResult);
    }

    public function test_redeem_ticket_rejects_invalid_ticket_format(): void
    {
        $this->assertNull($this->service->redeemTicket('*'));
        $this->assertNull($this->service->redeemTicket('?'));
        $this->assertNull($this->service->redeemTicket('short'));
        $this->assertNull($this->service->redeemTicket('invalid*glob?ticket-123456'));
    }

    public function test_decrypt_ticket_payload_rejects_tampered_ciphertext_or_mismatched_ticket_aad(): void
    {
        $ticket = 'valid-ticket-aad-0123456789abcdef';
        $otherTicket = 'other-ticket-aad-0123456789abcdef';
        $payload = [
            'user_id' => $this->owner->id,
            'virtual_machine_id' => $this->vm->id,
            'session_id' => 'session-aad-check-1',
        ];

        $encrypted = $this->service->encryptTicketPayload($payload, $ticket);
        /** @var array<string, mixed> $envelope */
        $envelope = json_decode($encrypted, true, 512, JSON_THROW_ON_ERROR);

        // Tamper with ciphertext
        $ctRaw = base64_decode((string) $envelope['ct'], true);
        $this->assertIsString($ctRaw);
        $tamperedCtEnvelope = $envelope;
        $tamperedCtEnvelope['ct'] = base64_encode($ctRaw ^ str_repeat("\x01", strlen($ctRaw)));
        $tamperedCtJson = json_encode($tamperedCtEnvelope, JSON_THROW_ON_ERROR);
        $this->assertNull($this->service->decryptTicketPayload($tamperedCtJson, $ticket));
        $this->fakeRedis->setex('console:ticket:'.$ticket, 60, $tamperedCtJson);
        $this->assertNull($this->service->redeemTicket($ticket));

        // Tamper with authentication tag
        $tagRaw = base64_decode((string) $envelope['tag'], true);
        $this->assertIsString($tagRaw);
        $tamperedTagEnvelope = $envelope;
        $tamperedTagEnvelope['tag'] = base64_encode($tagRaw ^ str_repeat("\x01", strlen($tagRaw)));
        $tamperedTagJson = json_encode($tamperedTagEnvelope, JSON_THROW_ON_ERROR);
        $this->assertNull($this->service->decryptTicketPayload($tamperedTagJson, $ticket));
        $this->fakeRedis->setex('console:ticket:'.$ticket, 60, $tamperedTagJson);
        $this->assertNull($this->service->redeemTicket($ticket));

        // Mismatched ticket AAD
        $this->assertNull($this->service->decryptTicketPayload($encrypted, $otherTicket));
        $this->fakeRedis->setex('console:ticket:'.$otherTicket, 60, $encrypted);
        $this->assertNull($this->service->redeemTicket($otherTicket));

        // Unencrypted JSON payload must be rejected (no plaintext fallback)
        $plaintextJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertNull($this->service->decryptTicketPayload($plaintextJson, $ticket));
        $this->fakeRedis->setex('console:ticket:'.$ticket, 60, $plaintextJson);
        $this->assertNull($this->service->redeemTicket($ticket));
    }

    public function test_decrypt_ticket_payload_supports_app_previous_keys_rotation(): void
    {
        $keyA = 'base64:'.base64_encode(random_bytes(32));
        $keyB = 'base64:'.base64_encode(random_bytes(32));
        $ticket = 'rotated-key-ticket-0123456789abcdef';
        $payload = [
            'user_id' => $this->owner->id,
            'virtual_machine_id' => $this->vm->id,
            'console_type' => 'console',
            'session_id' => 'rotated-session-1',
            'session_expires_at' => now()->addHour()->timestamp,
        ];

        config([
            'app.key' => $keyA,
            'app.previous_keys' => [],
        ]);

        $encrypted = $this->service->encryptTicketPayload($payload, $ticket);
        $this->fakeRedis->setex('console:ticket:'.$ticket, 60, $encrypted);

        config([
            'app.key' => $keyB,
            'app.previous_keys' => [$keyA],
        ]);

        $redeemed = $this->service->redeemTicket($ticket);
        $this->assertIsArray($redeemed);
        $this->assertSame($this->owner->id, $redeemed['user_id']);
        $this->assertSame($this->vm->id, $redeemed['virtual_machine_id']);
        $this->assertSame('rotated-session-1', $redeemed['session_id']);
    }

    public function test_issue_ticket_fails_if_vm_is_not_running(): void
    {
        $this->vm->status->transitionTo(Stopped::class);
        $this->vm->save();

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Interactive console is only available while the instance is running.');

        $this->service->issueTicket($this->vm, $this->owner);
    }

    public function test_issue_ticket_denies_admin_due_to_privacy_policy(): void
    {
        $this->expectException(HttpException::class);

        $this->service->issueTicket($this->vm, $this->admin);
    }

    public function test_issue_ticket_denies_other_user(): void
    {
        $this->expectException(HttpException::class);

        $this->service->issueTicket($this->vm, $this->otherUser);
    }

    public function test_issue_ticket_denies_active_impersonation(): void
    {
        $context = app(AuditContext::class);
        $context->setImpersonatorId($this->admin->id);

        try {
            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Issuing console tickets is forbidden while impersonating.');

            $this->service->issueTicket($this->vm, $this->owner);
        } finally {
            $context->setImpersonatorId(null);
        }
    }
}
