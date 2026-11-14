<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\DTO\Vm\VmActionDTO;
use App\Jobs\ExecuteVmActionJob;
use App\Jobs\ReinstallVmJob;
use App\Models\DriftReport;
use App\Models\Host;
use App\Models\Image;
use App\Models\User;
use App\Models\VirtualMachine;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Vm\Deleted;
use App\States\Vm\Deleting;
use App\States\Vm\Failed;
use App\States\Vm\Provisioning;
use App\States\Vm\Running;
use App\States\Vm\Stopped;
use App\States\Vm\Suspended;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Service managing individual virtual machine lifecycle transitions on hypervisor drivers.
 */
class VmLifecycleService
{
    public function __construct(
        private readonly HypervisorManager $hypervisorManager,
        private ?IpManagementService $ipManagementService = null,
        private ?AuditLogger $auditLogger = null,
    ) {
        $this->ipManagementService ??= app(IpManagementService::class);
        $this->auditLogger ??= app(AuditLogger::class);
    }

    private function logger(): AuditLogger
    {
        return $this->auditLogger ??= app(AuditLogger::class);
    }

    /**
     * Authorize and dispatch lifecycle actions (start, stop, restart, suspend, delete, reinstall).
     *
     * @param  array<string, mixed>|null  $cloudInit
     *
     * @throws ValidationException
     */
    public function handleActionRequest(
        VirtualMachine $vm,
        string $action,
        bool $force = false,
        ?User $user = null,
        ?string $osImage = null,
        ?array $cloudInit = null,
        ?VmActionDTO $dto = null,
    ): void {
        if ($dto !== null) {
            $osImage ??= $dto->osImage;
            $cloudInit ??= $dto->cloudInit;
        }

        $gateResponse = Gate::forUser($user)->inspect('executeAction', $vm);
        if ($gateResponse->denied()) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm,
                tenantUserId: $vm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => $action,
                    'reason' => 'policy_denial',
                ],
            ));

            abort(403, $gateResponse->message() ?? 'Forbidden');
        }

        $canPerform = match ($action) {
            'start' => $vm->status->equals(Stopped::class) || $vm->status->equals(Suspended::class),
            'stop' => $vm->status->equals(Running::class) || $vm->status->equals(Suspended::class),
            'restart' => $vm->status->equals(Running::class) || $vm->status->equals(Stopped::class) || $vm->status->equals(Suspended::class),
            'suspend' => $vm->status->equals(Running::class) || $vm->status->equals(Stopped::class),
            'delete' => ! $vm->status->equals(Deleting::class) && ! $vm->status->equals(Deleted::class) && ! $vm->status->equals(Provisioning::class),
            'reinstall' => $vm->status->equals(Running::class) || $vm->status->equals(Stopped::class) || $vm->status->equals(Suspended::class) || $vm->status->equals(Failed::class),
            default => false,
        };

        if (! $canPerform) {
            $this->logger()->recordIndependent(new AuditEntry(
                action: AuditAction::VmActionDenied,
                outcome: AuditOutcome::Denied,
                subject: $vm,
                tenantUserId: $vm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => $action,
                    'reason' => 'invalid_state_transition',
                    'current_state' => (string) $vm->status,
                ],
            ));

            $msg = __('messages.vm.invalid_state_transition', [
                'action' => $action,
                'state' => (string) $vm->status,
            ]);
            abort(409, is_string($msg) ? $msg : 'Invalid state transition.');
        }

        if ($action === 'reinstall') {
            if ($osImage !== null && $osImage !== '') {
                $allowedImage = Image::where('name', $osImage)->where('enabled', true)->first();
                if ($allowedImage === null) {
                    throw ValidationException::withMessages([
                        'os_image' => ['The selected image is not in the allowlist or is disabled.'],
                    ]);
                }

                if ($vm->instance_type === 'container' && $allowedImage->os_type !== 'linux') {
                    throw ValidationException::withMessages([
                        'os_image' => ['Containers (LXC) can only run Linux operating system images.'],
                    ]);
                }
            }

            if ($vm->host_id === null) {
                throw ValidationException::withMessages([
                    'host_id' => [__('messages.host.not_active_or_available')],
                ]);
            }

            $host = $vm->host ?? Host::find($vm->host_id);
            if ($host === null || ! $host->is_active) {
                throw ValidationException::withMessages([
                    'host_id' => [__('messages.host.not_active_or_available')],
                ]);
            }

            DB::transaction(function () use ($vm, $osImage, $cloudInit): void {
                $vm->status->transitionTo(Provisioning::class);
                $vm->save();

                $this->logger()->record(new AuditEntry(
                    action: AuditAction::VmReinstallRequested,
                    outcome: AuditOutcome::Success,
                    subject: $vm,
                    tenantUserId: $vm->user_id,
                    visibility: 'tenant',
                    context: [
                        'os_image' => ($osImage !== null && $osImage !== '') ? $osImage : $vm->os_image,
                    ],
                ));

                ReinstallVmJob::dispatch($vm, $osImage, $cloudInit)->afterCommit();
            });

            return;
        }

        $isAdmin = $user !== null && $user->hasRole('admin');
        $actualForce = ($action === 'delete') ? ($isAdmin && $force) : $force;

        if ($action === 'delete' && $actualForce) {
            $this->logger()->record(new AuditEntry(
                action: AuditAction::VmDeleteForced,
                outcome: AuditOutcome::Success,
                subject: $vm,
                tenantUserId: $vm->user_id,
                visibility: 'tenant',
                context: [
                    'action' => 'delete',
                    'forced' => true,
                ],
            ));
        }

        $this->logger()->record(new AuditEntry(
            action: AuditAction::VmActionRequested,
            outcome: AuditOutcome::Success,
            subject: $vm,
            tenantUserId: $vm->user_id,
            visibility: 'tenant',
            context: [
                'action' => $action,
                'force' => $actualForce,
            ],
        ));

        ExecuteVmActionJob::dispatch($vm, $action, $actualForce);
    }

    /**
     * Authorize and dispatch virtual machine reinstallation.
     *
     * @param  array<string, mixed>|null  $cloudInit
     *
     * @throws ValidationException
     */
    public function handleReinstallRequest(
        VirtualMachine $vm,
        ?string $osImage = null,
        ?array $cloudInit = null,
        ?User $user = null,
    ): void {
        $this->handleActionRequest(
            vm: $vm,
            action: 'reinstall',
            force: false,
            user: $user,
            osImage: $osImage,
            cloudInit: $cloudInit,
        );
    }

    /**
     * Issue an interactive console ticket for a virtual machine.
     */
    public function issueConsoleTicket(VirtualMachine $vm, ?User $user = null): string
    {
        return app(ConsoleTicketService::class)->issueTicket($vm, $user)->ticket;
    }

    /**
     * Suspend a virtual machine via the hypervisor.
     */
    public function suspend(VirtualMachine $vm): bool
    {
        if ($vm->status->equals(Suspended::class)) {
            return true;
        }

        $driver = $this->hypervisorManager->driverForVm($vm);
        $success = $driver->suspendInstance($vm);

        if (! $success) {
            throw new RuntimeException(sprintf('Hypervisor failed to suspend VM #%d (%s)', $vm->id, $vm->name));
        }

        $vm->status->transitionTo(Suspended::class);
        $vm->save();

        return true;
    }

    /**
     * Delete a virtual machine via the hypervisor.
     */
    public function delete(VirtualMachine $vm, bool $force = false): bool
    {
        if ($vm->status->equals(Deleted::class)) {
            return true;
        }

        if (! $vm->status->equals(Deleting::class)) {
            $vm->status->transitionTo(Deleting::class);
            $vm->save();
        }

        $driver = $this->hypervisorManager->driverForVm($vm);
        $driverSucceeded = false;
        $hypervisorError = null;

        try {
            $driverSucceeded = $driver->deleteInstance($vm);
        } catch (Throwable $driverEx) {
            if ($this->isNotFound($driverEx)) {
                $driverSucceeded = true;
            } elseif ($force) {
                Log::warning(sprintf('Force deleting VM #%d despite hypervisor error: %s', $vm->id, $driverEx->getMessage()));
                $driverSucceeded = true;
                $hypervisorError = $driverEx;
            } else {
                throw $driverEx;
            }
        }

        if (! $driverSucceeded) {
            throw new RuntimeException(sprintf('Hypervisor failed to delete VM #%d (%s)', $vm->id, $vm->name));
        }

        DB::transaction(function () use ($vm, $hypervisorError): void {
            if (! $vm->status->equals(Deleted::class)) {
                $vm->status->transitionTo(Deleted::class);
                $vm->save();
            }

            $this->ipManagementService->releaseIp($vm);

            if ($hypervisorError !== null && $vm->host_id !== null) {
                DriftReport::create([
                    'host_id' => $vm->host_id,
                    'virtual_machine_id' => $vm->id,
                    'instance_name' => $vm->name,
                    'incus_project' => $vm->incus_project ?? 'default',
                    'kind' => 'orphan_instance',
                    'expected_state' => 'deleted',
                    'actual_state' => null,
                    'details' => [
                        'error' => $hypervisorError->getMessage(),
                        'forced' => true,
                        'detected_by' => 'force_delete',
                    ],
                    'resolved_at' => null,
                ]);
            }
        });

        return true;
    }

    /**
     * Start a virtual machine via the hypervisor.
     */
    public function start(VirtualMachine $vm): bool
    {
        $driver = $this->hypervisorManager->driverForVm($vm);
        $success = $driver->startInstance($vm);

        if (! $success) {
            throw new RuntimeException(sprintf('Hypervisor failed to start VM #%d (%s)', $vm->id, $vm->name));
        }

        $vm->status->transitionTo(Running::class);
        $vm->save();

        return true;
    }

    /**
     * Stop a virtual machine via the hypervisor.
     */
    public function stop(VirtualMachine $vm, bool $force = false): bool
    {
        $driver = $this->hypervisorManager->driverForVm($vm);
        $success = $driver->stopInstance($vm, $force);

        if (! $success) {
            throw new RuntimeException(sprintf('Hypervisor failed to stop VM #%d (%s)', $vm->id, $vm->name));
        }

        $vm->status->transitionTo(Stopped::class);
        $vm->save();

        return true;
    }

    /**
     * Restart a virtual machine via the hypervisor (stop followed by start).
     */
    public function restart(VirtualMachine $vm): bool
    {
        $driver = $this->hypervisorManager->driverForVm($vm);
        $stopSuccess = $driver->stopInstance($vm, true);
        if (! $stopSuccess) {
            throw new RuntimeException(sprintf('Hypervisor failed to stop VM #%d (%s) during restart', $vm->id, $vm->name));
        }
        $startSuccess = $driver->startInstance($vm);
        if (! $startSuccess) {
            throw new RuntimeException(sprintf('Hypervisor failed to start VM #%d (%s) during restart', $vm->id, $vm->name));
        }
        $vm->status->transitionTo(Running::class);
        $vm->save();

        return true;
    }

    /**
     * Determine if an exception represents a 404 Not Found response from hypervisor.
     */
    private function isNotFound(Throwable $e): bool
    {
        if ($e->getCode() === 404) {
            return true;
        }

        if ($e instanceof RequestException && $e->response?->status() === 404) {
            return true;
        }

        if (method_exists($e, 'getStatusCode') && $e->getStatusCode() === 404) {
            return true;
        }

        if (method_exists($e, 'getResponse') && $e->getResponse() !== null && method_exists($e->getResponse(), 'getStatusCode') && $e->getResponse()->getStatusCode() === 404) {
            return true;
        }

        return false;
    }
}
