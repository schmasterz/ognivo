<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditContext;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Vm\ConsoleSessionDTO;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Running;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

final class ConsoleTicketService
{
    public function __construct(
        protected readonly HypervisorManager $hypervisorManager,
        protected readonly ?AuditLogger $auditLogger = null,
        protected readonly ?AuditContext $auditContext = null,
    ) {}

    protected function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    protected function context(): AuditContext
    {
        return $this->auditContext ?? app(AuditContext::class);
    }

    /**
     * Issue an interactive console session ticket for a virtual machine.
     *
     * @param  string  $type  Console type ('console' for text/serial, 'vga' for graphical display)
     */
    public function issueTicket(VirtualMachine $vm, ?User $user = null, string $type = 'console'): ConsoleSessionDTO
    {
        $auditContext = $this->context();
        /** @var mixed $userToken */
        $userToken = $user?->currentAccessToken();
        $isImpersonated = $auditContext->getImpersonatorId() !== null
            || ($user !== null && $userToken instanceof PersonalAccessToken && $user->tokenCan('impersonated'));

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

        $driver = $this->hypervisorManager->driverForVm($vm);
        $session = $driver->createConsoleSession($vm, $type);

        $ticket = Str::random(64);
        $expiresAt = now()->addSeconds(60);

        $host = $vm->host;
        if ($host === null) {
            throw new \RuntimeException(sprintf('VirtualMachine #%d has no assigned host.', $vm->id));
        }

        $payload = [
            'user_id' => $user !== null ? $user->id : $vm->user_id,
            'virtual_machine_id' => $vm->id,
            'instance_type' => $vm->instance_type,
            'console_type' => $type,
            'host_endpoint' => $host->endpoint_url,
            'host_driver' => $host->driver,
            'server_public_key_pin' => $host->server_public_key_pin,
            'server_fingerprint' => $host->server_fingerprint,
            'websocket_path' => $session['websocket_path'],
            'control_path' => $session['control_path'] ?? null,
            'created_at' => time(),
        ];

        $key = sprintf('console:ticket:%s', $ticket);
        Redis::setex($key, 60, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->logger()->record(new AuditEntry(
            action: AuditAction::ConsoleTicketIssued,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            visibility: 'tenant',
            context: [
                'virtual_machine_id' => $vm->id,
                'console_type' => $type,
            ],
        ));

        return new ConsoleSessionDTO(
            virtualMachineId: $vm->id,
            ticket: $ticket,
            websocketPath: sprintf('/console?ticket=%s', $ticket),
            expiresAt: $expiresAt->toIso8601String(),
            type: $type,
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
}
