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
use App\States\Host\Unreachable;
use App\States\Vm\Running;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ConsoleTicketService
{
    public const TICKET_HKDF_INFO = 'ognivo-console-ticket-v1';

    public const TICKET_CIPHER = 'aes-256-gcm';

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
     * @param  int|null  $sessionExpiresAt  Unix timestamp when the backing browser session expires
     */
    public function issueTicket(
        VirtualMachine $vm,
        ?User $user = null,
        string $type = 'console',
        int $width = 80,
        int $height = 24,
        ?string $preferredDriver = null,
        ?int $sessionExpiresAt = null,
    ): ConsoleSessionDTO {
        $auditContext = $this->context();
        $isImpersonated = $auditContext->getImpersonatorId() !== null;

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

        if ($vm->host !== null && $vm->host->status->equals(Unreachable::class)) {
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

            $openedAt = Cast::float($entry['opened_at'] ?? 0);
            $lastActivity = Cast::float($entry['last_activity_at'] ?? $openedAt);
            $ticketExpiresAt = isset($entry['ticket_expires_at']) ? Cast::float($entry['ticket_expires_at']) : null;
            $isRedeemed = ! empty($entry['redeemed_at']) || ($openedAt > 0 && $lastActivity > $openedAt);

            $pruneReason = null;
            if (! $isRedeemed && $ticketExpiresAt !== null && $now >= $ticketExpiresAt) {
                $pruneReason = 'ticket_expired';
            } elseif ($now - $lastActivity >= $idleTimeout) {
                $pruneReason = 'idle_timeout';
            }

            if ($pruneReason !== null) {
                $deleted = 0;
                try {
                    $deleted = Cast::int(Redis::hdel($vmSessionsKey, Cast::string($sid)));
                    Redis::hdel('console:session_index', Cast::string($sid));
                } catch (Throwable) {
                }
                if ($deleted > 0) {
                    $this->closeSessionInternal($vm, Cast::string($sid), $entry, $pruneReason);
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
            'ticket_expires_at' => $now + (float) $ticketTtl,
            'redeemed_at' => null,
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
            'session_expires_at' => $sessionExpiresAt,
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
        Redis::setex($key, $ticketTtl, $this->encryptTicketPayload($payload, $ticket));

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
            $raw = Redis::command('eval', [$lua, 1, $key]);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = $this->decryptTicketPayload($raw, $ticket);
            if (! is_array($data)) {
                return null;
            }

            /** @var mixed $rawSessionExpiresAt */
            $rawSessionExpiresAt = $data['session_expires_at'] ?? null;
            if (! is_int($rawSessionExpiresAt) && ! is_numeric($rawSessionExpiresAt)) {
                return null;
            }

            if ((int) $rawSessionExpiresAt <= time()) {
                return null;
            }

            if (! empty($data['virtual_machine_id']) && ! empty($data['session_id'])) {
                try {
                    $vmSessionsKey = sprintf('console:vm_sessions:%d', Cast::int($data['virtual_machine_id']));
                    $sessionId = Cast::string($data['session_id']);
                    /** @var mixed $rawRecord */
                    $rawRecord = Redis::hget($vmSessionsKey, $sessionId);
                    if (is_string($rawRecord) && $rawRecord !== '') {
                        /** @var mixed $record */
                        $record = json_decode($rawRecord, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($record)) {
                            $redeemedAt = microtime(true);
                            $record['redeemed_at'] = $redeemedAt;
                            $record['last_activity_at'] = $redeemedAt;
                            Redis::hset($vmSessionsKey, $sessionId, json_encode($record, JSON_THROW_ON_ERROR));
                        }
                    }
                } catch (Throwable) {
                }
            }

            return $data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Encrypt a console ticket payload with AES-256-GCM bound to the ticket ID as AAD.
     *
     * @param  array<string, mixed>  $payload
     */
    public function encryptTicketPayload(array $payload, string $ticket): string
    {
        $keys = $this->resolveTicketKeys();
        $plaintext = json_encode($payload, JSON_THROW_ON_ERROR);

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::TICKET_CIPHER,
            $keys[0],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $ticket,
            16,
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Failed to encrypt console ticket payload.');
        }

        return json_encode([
            'v' => 1,
            'alg' => 'AES-256-GCM',
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ciphertext),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Decrypt a console ticket payload encrypted with AES-256-GCM.
     *
     * @return array<string, mixed>|null
     */
    public function decryptTicketPayload(string $raw, string $ticket): ?array
    {
        try {
            /** @var mixed $envelope */
            $envelope = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($envelope)) {
            return null;
        }

        $ivB64 = $envelope['iv'] ?? null;
        $tagB64 = $envelope['tag'] ?? null;
        $ctB64 = $envelope['ct'] ?? null;

        if (($envelope['v'] ?? null) !== 1 || ! is_string($ivB64) || ! is_string($tagB64) || ! is_string($ctB64)) {
            return null;
        }

        $iv = base64_decode($ivB64, true);
        $tag = base64_decode($tagB64, true);
        $ct = base64_decode($ctB64, true);

        if ($iv === false || $tag === false || $ct === false) {
            return null;
        }

        if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ct === '') {
            return null;
        }

        foreach ($this->resolveTicketKeys() as $derivedKey) {
            $decrypted = openssl_decrypt(
                $ct,
                self::TICKET_CIPHER,
                $derivedKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $ticket,
            );

            if ($decrypted !== false) {
                try {
                    /** @var mixed $payload */
                    $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    return null;
                }

                if (! is_array($payload)) {
                    return null;
                }

                /** @var array<string, mixed> $payload */
                return $payload;
            }
        }

        return null;
    }

    /**
     * Derive 32-byte encryption keys from APP_KEY and APP_PREVIOUS_KEYS via HKDF-SHA256.
     *
     * @return non-empty-list<string>
     */
    private function resolveTicketKeys(): array
    {
        $rawCandidates = [];

        /** @var mixed $primary */
        $primary = config('app.key');
        if (is_string($primary) && $primary !== '') {
            $rawCandidates[] = $primary;
        }

        /** @var mixed $previous */
        $previous = config('app.previous_keys', []);
        if (is_string($previous) && $previous !== '') {
            foreach (explode(',', $previous) as $item) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $rawCandidates[] = $trimmed;
                }
            }
        } elseif (is_array($previous)) {
            foreach ($previous as $item) {
                if (is_string($item) && $item !== '') {
                    $rawCandidates[] = $item;
                }
            }
        }

        $keys = [];
        foreach ($rawCandidates as $keyStr) {
            if (str_starts_with($keyStr, 'base64:')) {
                $raw = base64_decode(substr($keyStr, 7), true);
                if ($raw === false || $raw === '') {
                    continue;
                }
            } else {
                $raw = $keyStr;
            }

            $keys[] = hash_hkdf('sha256', $raw, 32, self::TICKET_HKDF_INFO);
        }

        if ($keys === []) {
            throw new \RuntimeException('APP_KEY is not configured for console ticket encryption.');
        }

        return $keys;
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
