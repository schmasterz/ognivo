<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Cast;
use App\DTO\Vm\ConsoleSessionDTO;
use App\Exceptions\FingerprintMismatchException;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\Services\Terminal\Drivers\ConsoleDriver;
use App\Services\Terminal\Drivers\ExecDriver;
use App\States\Vm\Running;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ConsoleTicketService
{
    public function __construct(
        protected readonly HypervisorManager $hypervisorManager,
        protected readonly ?AuditLogger $auditLogger = null,
        protected readonly ?AuditContext $auditContext = null,
        protected readonly ?PanelCertificateService $certService = null,
        protected readonly ?ExecDriver $execDriver = null,
        protected readonly ?ConsoleDriver $consoleDriver = null,
    ) {}

    protected function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    protected function context(): AuditContext
    {
        return $this->auditContext ?? app(AuditContext::class);
    }

    protected function execDriver(): ExecDriver
    {
        return $this->execDriver ?? app(ExecDriver::class);
    }

    protected function consoleDriver(): ConsoleDriver
    {
        return $this->consoleDriver ?? app(ConsoleDriver::class);
    }

    /**
     * Issue an interactive console session ticket for a virtual machine.
     *
     * @param  string  $type  Console type ('console' for text/serial, 'vga' for graphical display)
     * @param  int  $width  Terminal columns (clamped to 20-500)
     * @param  int  $height  Terminal rows (clamped to 5-200)
     * @param  string|null  $preferredDriver  Preferred terminal driver ('auto', 'exec', 'console')
     */
    public function issueTicket(
        VirtualMachine $vm,
        ?User $user = null,
        string $type = 'console',
        int $width = 80,
        int $height = 24,
        ?string $preferredDriver = null,
    ): ConsoleSessionDTO {
        $auditContext = $this->context();
        /** @var mixed $userToken */
        $userToken = $user?->currentAccessToken();
        $isImpersonated = $auditContext->getImpersonatorId() !== null
            || ($userToken instanceof PersonalAccessToken && AuditContext::isImpersonatedToken($userToken));

        if ($isImpersonated) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm,
                tenantUserId: $vm->user_id,
                impersonatorId: $auditContext->getImpersonatorId(),
                visibility: 'tenant',
                context: [
                    'action' => 'console.ticket',
                    'reason' => 'forbidden_while_impersonating',
                ],
            ));

            abort(403, 'Issuing console tickets is forbidden while impersonating.');
        }

        $gateResponse = Gate::forUser($user)->inspect('viewConsole', $vm);
        if ($gateResponse->denied()) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm,
                tenantUserId: $vm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => 'console.ticket',
                    'reason' => 'policy_denial',
                ],
            ));

            abort(403, $gateResponse->message() ?? 'Forbidden');
        }

        if (! $vm->status->equals(Running::class)) {
            throw new HttpException(409, 'Interactive console is only available while the instance is running.');
        }

        $width = max(20, min(500, $width));
        $height = max(5, min(200, $height));

        if ($vm->instance_type === 'container' && $type === 'vga') {
            throw new InvalidArgumentException('Containers do not support graphical VGA console.');
        }

        if ($vm->host !== null && $vm->host->status === 'unreachable') {
            $exception = ValidationException::withMessages([
                'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
            ]);
            $exception->errorBag = 'default';
            $exception->response = response()->json([
                'message' => 'Host Unreachable',
                'errors' => [
                    'host' => ['The compute node hosting this virtual machine is currently unreachable.'],
                ],
            ], 422);
            throw $exception;
        }

        $ticketTtl = max(10, Cast::int(config('hypervisor.terminal.ticket_ttl_seconds'), 60));
        $idleTimeout = max(30, Cast::int(config('hypervisor.terminal.idle_timeout_seconds'), 900));
        $maxExec = max(1, Cast::int(config('hypervisor.terminal.max_exec_sessions_per_instance'), 5));
        $maxConsole = max(1, Cast::int(config('hypervisor.terminal.max_console_sessions_per_instance'), 1));
        $maxMessageBytes = max(1024, Cast::int(config('hypervisor.terminal.max_message_bytes'), 65536));

        $vmSessionsKey = sprintf('console:vm_sessions:%d', $vm->id);

        // Prune idle sessions and count active sessions per driver
        $activeSessions = [];
        try {
            $rawHash = Redis::hgetall($vmSessionsKey);
            if (is_array($rawHash)) {
                $activeSessions = $rawHash;
            }
        } catch (Throwable) {
            $activeSessions = [];
        }

        $now = microtime(true);
        $activeExecCount = 0;
        $activeConsoleCount = 0;

        foreach ($activeSessions as $sid => $rawJson) {
            if ($rawJson === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $entry */
                $entry = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }

            $lastActivity = Cast::float($entry['last_activity_at'] ?? $entry['opened_at'] ?? 0);
            if ($now - $lastActivity >= $idleTimeout) {
                $deleted = 0;
                try {
                    $deleted = Cast::int(Redis::hdel($vmSessionsKey, Cast::string($sid)));
                    Redis::hdel('console:session_index', Cast::string($sid));
                } catch (Throwable) {
                }
                if ($deleted > 0) {
                    $this->closeSessionInternal($vm, Cast::string($sid), $entry, 'idle_timeout');
                }

                continue;
            }

            $driverName = Cast::string($entry['driver'] ?? 'exec');
            if ($driverName === 'console') {
                $activeConsoleCount++;
            } else {
                $activeExecCount++;
            }
        }

        // Automatic driver selection & opening
        $resolvedDriver = 'exec';
        $fallbackReason = null;

        if ($type === 'vga' || $preferredDriver === 'console') {
            if ($activeConsoleCount >= $maxConsole) {
                throw new HttpException(409, 'A serial console session is already active for this instance.');
            }
            $session = $this->consoleDriver()->open($vm, $width, $height, $type);
            $resolvedDriver = 'console';
        } elseif ($vm->instance_type === 'container' || $preferredDriver === 'exec') {
            if ($activeExecCount >= $maxExec) {
                throw new HttpException(429, 'Maximum number of concurrent terminal sessions reached for this instance.');
            }
            $session = $this->execDriver()->open($vm, $width, $height, $type);
            $resolvedDriver = 'exec';
        } else {
            // Automatic mode for virtual machines
            if ($activeExecCount >= $maxExec) {
                throw new HttpException(429, 'Maximum number of concurrent terminal sessions reached for this instance.');
            }

            try {
                $session = $this->execDriver()->open($vm, $width, $height, $type);
                $resolvedDriver = 'exec';
            } catch (FingerprintMismatchException $e) {
                throw $e;
            } catch (Throwable $e) {
                if ($this->isAgentUnavailableError($e)) {
                    if ($activeConsoleCount >= $maxConsole) {
                        throw new HttpException(409, 'Guest agent is unavailable and the serial console is already in use.');
                    }
                    $session = $this->consoleDriver()->open($vm, $width, $height, 'console');
                    $resolvedDriver = 'console';
                    $fallbackReason = 'agent_unavailable';
                } else {
                    throw $e;
                }
            }
        }

        $sessionId = (string) Str::uuid();
        $ticket = Str::random(64);
        $closeToken = Str::random(40);
        $expiresAt = now()->addSeconds($ticketTtl);

        $host = $vm->host;
        if ($host === null) {
            throw new \RuntimeException(sprintf('VirtualMachine #%d has no assigned host.', $vm->id));
        }

        $certService = $this->certService ?? app(PanelCertificateService::class);
        $clientCert = $certService->getActiveCertificate() ?? $certService->getRetiringCertificate();
        $usableCert = ($clientCert !== null && $clientCert->isUsable()) ? $clientCert : null;

        $actorId = $user !== null ? $user->id : $vm->user_id;

        // Store active session metadata in Redis hash
        $sessionRecord = [
            'session_id' => $sessionId,
            'virtual_machine_id' => $vm->id,
            'user_id' => $actorId,
            'driver' => $resolvedDriver,
            'console_type' => $type,
            'operation_id' => $session['operation_id'],
            'opened_at' => $now,
            'last_activity_at' => $now,
            'close_token' => $closeToken,
        ];

        try {
            Redis::hset($vmSessionsKey, $sessionId, json_encode($sessionRecord, JSON_THROW_ON_ERROR));
            Redis::expire($vmSessionsKey, $idleTimeout + 60);
            Redis::hset('console:session_index', $sessionId, (string) $vm->id);
        } catch (Throwable) {
            // Defensive: allows Mockery spies or Redis connectivity blips to degrade gracefully
        }

        $payload = [
            'user_id' => $actorId,
            'virtual_machine_id' => $vm->id,
            'instance_type' => $vm->instance_type,
            'console_type' => $type,
            'host_endpoint' => $host->endpoint_url,
            'host_driver' => $host->driver,
            'server_public_key_pin' => $host->server_public_key_pin,
            'server_fingerprint' => $host->server_fingerprint,
            'websocket_path' => $session['websocket_path'],
            'control_path' => $session['control_path'] ?? null,
            'client_certificate' => $usableCert?->certificate,
            'client_private_key' => $usableCert?->private_key,
            'created_at' => time(),
            'session_id' => $sessionId,
            'driver' => $resolvedDriver,
            'operation_id' => $session['operation_id'],
            'width' => $width,
            'height' => $height,
            'idle_timeout_seconds' => $idleTimeout,
            'max_message_bytes' => $maxMessageBytes,
            'close_token' => $closeToken,
            'fallback_reason' => $fallbackReason,
        ];

        $key = sprintf('console:ticket:%s', $ticket);
        Redis::setex($key, $ticketTtl, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ConsoleTicketIssued,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            actorType: 'user',
            actorId: $actorId,
            visibility: 'tenant',
            context: [
                'virtual_machine_id' => $vm->id,
                'console_type' => $type,
                'driver' => $resolvedDriver,
                'session_id' => $sessionId,
            ],
        ));

        $this->logger()->record(new AuditEntry(
            action: AuditAction::TerminalSessionOpened,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            actorType: 'user',
            actorId: $actorId,
            visibility: 'tenant',
            context: [
                'virtual_machine_id' => $vm->id,
                'session_id' => $sessionId,
                'driver' => $resolvedDriver,
                'console_type' => $type,
                'fallback_reason' => $fallbackReason,
            ],
        ));

        return new ConsoleSessionDTO(
            virtualMachineId: $vm->id,
            ticket: $ticket,
            websocketPath: '/console',
            expiresAt: $expiresAt->toIso8601String(),
            type: $type,
            driver: $resolvedDriver,
            sessionId: $sessionId,
            fallbackReason: $fallbackReason,
            idleTimeout: $idleTimeout,
        );
    }

    /**
     * Atomically redeem a console ticket from Redis (single-use guarantee).
     *
     * @return array<string, mixed>|null
     */
    public function redeemTicket(string $ticket): ?array
    {
        if (trim($ticket) === '' || ! preg_match('/^[A-Za-z0-9_-]{16,128}$/', $ticket)) {
            return null;
        }

        $key = sprintf('console:ticket:%s', $ticket);
        $raw = null;

        try {
            /** @var mixed $raw */
            $raw = Redis::command('getdel', [$key]);
        } catch (Throwable) {
            $lua = "local v = redis.call('get', KEYS[1]); if v then redis.call('del', KEYS[1]); end; return v";
            /** @var mixed $raw */
            $raw = Redis::eval($lua, [$key], 1);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Close an active terminal session by session ID.
     */
    public function closeSession(
        VirtualMachine $vm,
        string $sessionId,
        ?User $user = null,
        string $reason = 'client_disconnect',
    ): bool {
        if ($user !== null) {
            Gate::forUser($user)->authorize('viewConsole', $vm);
        }

        $vmSessionsKey = sprintf('console:vm_sessions:%d', $vm->id);
        $raw = null;
        try {
            /** @var mixed $raw */
            $raw = Redis::hget($vmSessionsKey, $sessionId);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            /** @var array<string, mixed> $record */
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $record = [];
        }

        try {
            $deleted = Cast::int(Redis::hdel($vmSessionsKey, $sessionId));
            Redis::hdel('console:session_index', $sessionId);
            if ($deleted === 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return $this->closeSessionInternal($vm, $sessionId, $record, $reason);
    }

    /**
     * Close an active terminal session using sessionId and closeToken (for unauthenticated proxy callbacks).
     */
    public function closeSessionByToken(
        string $sessionId,
        string $closeToken,
        string $reason = 'client_disconnect',
    ): bool {
        if (trim($sessionId) === '' || trim($closeToken) === '') {
            return false;
        }

        $vmIdRaw = null;
        try {
            /** @var mixed $vmIdRaw */
            $vmIdRaw = Redis::hget('console:session_index', $sessionId);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($vmIdRaw) || $vmIdRaw === '') {
            return false;
        }

        $vmId = (int) $vmIdRaw;
        $vm = VirtualMachine::find($vmId);
        if ($vm === null) {
            try {
                Redis::hdel('console:session_index', $sessionId);
            } catch (Throwable) {
            }

            return false;
        }

        $vmSessionsKey = sprintf('console:vm_sessions:%d', $vmId);
        $raw = null;
        try {
            /** @var mixed $raw */
            $raw = Redis::hget($vmSessionsKey, $sessionId);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($raw) || $raw === '') {
            return false;
        }

        try {
            /** @var array<string, mixed> $record */
            $record = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return false;
        }

        $expectedToken = Cast::string($record['close_token'] ?? '');
        if ($expectedToken === '' || ! hash_equals($expectedToken, $closeToken)) {
            return false;
        }

        try {
            $deleted = Cast::int(Redis::hdel($vmSessionsKey, $sessionId));
            Redis::hdel('console:session_index', $sessionId);
            if ($deleted === 0) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return $this->closeSessionInternal($vm, $sessionId, $record, $reason);
    }

    /**
     * Internal cleanup logic for a closed terminal session.
     *
     * @param  array<string, mixed>  $record
     */
    protected function closeSessionInternal(
        VirtualMachine $vm,
        string $sessionId,
        array $record,
        string $reason,
    ): bool {
        $now = microtime(true);
        $openedAt = Cast::float($record['opened_at'] ?? $now, $now);
        $durationSeconds = max(
            0.0,
            round($now - $openedAt, 2)
        );

        $operationId = Cast::string($record['operation_id'] ?? '');
        if ($operationId !== '') {
            try {
                $this->hypervisorManager->driverForVm($vm)->cancelOperation($vm, $operationId);
            } catch (Throwable) {
                // Hypervisor operation may already have exited or host may be unreachable
            }
        }

        $actorId = isset($record['user_id']) ? Cast::int($record['user_id'], $vm->user_id) : $vm->user_id;

        $this->logger()->record(new AuditEntry(
            action: AuditAction::TerminalSessionClosed,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            actorType: 'user',
            actorId: $actorId,
            visibility: 'tenant',
            context: [
                'virtual_machine_id' => $vm->id,
                'session_id' => $sessionId,
                'driver' => Cast::string($record['driver'] ?? 'exec'),
                'duration_seconds' => $durationSeconds,
                'reason' => $reason,
            ],
        ));

        return true;
    }

    /**
     * Determine whether an exception indicates the guest agent is not running/available.
     */
    protected function isAgentUnavailableError(Throwable $e): bool
    {
        $message = strtolower($e->getMessage().' '.($e->getPrevious()?->getMessage() ?? ''));
        $needles = ['agent', 'vsock', 'not running', 'connection refused', 'timed out', 'disconnected'];

        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
