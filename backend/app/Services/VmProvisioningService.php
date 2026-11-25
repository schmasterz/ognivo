<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Exceptions\IpPoolExhaustedException;
use App\Jobs\ProvisionVmJob;
use App\Models\Host;
use App\Models\Image;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\States\Vm\Provisioning;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrating race-free validation, quota enforcement, IPAM allocation, and VM provisioning.
 */
final class VmProvisioningService
{
    public function __construct(
        private readonly IpManagementService $ipService,
        private readonly HostSelector $hostSelector,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly HostCapacityGuard $capacityGuard = new HostCapacityGuard,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    /**
     * Provision a new virtual machine or container under strict quota constraints and dynamic IPAM.
     *
     * @param array{
     *     name: string,
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     os_image: string,
     *     instance_type?: string,
     *     host_id?: int|null,
     *     cloud_init?: array<string, mixed>|null
     * } $data
     *
     * @throws ValidationException
     */
    public function provision(User $user, array $data): VirtualMachine
    {
        if ($user->status !== 'active') {
            $msgKey = match ($user->status) {
                'suspended' => 'messages.auth.account_suspended',
                'inactive' => 'messages.auth.account_inactive',
                'pending' => 'messages.auth.account_pending_verification',
                default => 'messages.auth.account_inactive',
            };

            throw ValidationException::withMessages([
                'user' => [__($msgKey)],
            ]);
        }

        // 1. Validate platform virtualization mode support
        $instanceType = $data['instance_type'] ?? null;
        if ($instanceType === null) {
            $instanceType = SystemSetting::isVmEnabled() ? 'virtual-machine' : 'container';
        }

        if ($instanceType === 'virtual-machine' && ! SystemSetting::isVmEnabled()) {
            /** @var string $msg */
            $msg = __('messages.vm.vm_mode_disabled');
            throw ValidationException::withMessages([
                'instance_type' => [$msg],
            ]);
        }

        if ($instanceType === 'container' && ! SystemSetting::isContainerEnabled()) {
            /** @var string $msg */
            $msg = __('messages.vm.container_mode_disabled');
            throw ValidationException::withMessages([
                'instance_type' => [$msg],
            ]);
        }

        // 2. Enforce admin-approved image allowlist
        $allowedImage = Image::where('name', $data['os_image'])->where('enabled', true)->first();
        if ($allowedImage === null) {
            throw ValidationException::withMessages([
                'os_image' => ['The selected image is not in the allowlist or is disabled.'],
            ]);
        }

        // 3. Enforce that containers (LXC) can only run Linux images
        if ($instanceType === 'container' && $allowedImage->os_type !== 'linux') {
            throw ValidationException::withMessages([
                'instance_type' => ['Containers (LXC) can only run Linux operating system images.'],
            ]);
        }

        // 4. Optimistic Host selection / validation before entering transaction
        $hostId = $data['host_id'] ?? null;
        if ($hostId === null) {
            $chosenHost = $this->hostSelector->select([
                'os_image' => $data['os_image'],
                'instance_type' => $instanceType,
                'cpu_cores' => $data['cpu_cores'],
                'ram_mb' => $data['ram_mb'],
                'disk_gb' => $data['disk_gb'],
            ]);

            if ($chosenHost === null) {
                $hasAnyActiveHost = Host::where('is_active', true)->where('status', 'ok')->exists();
                $errorMsg = $hasAnyActiveHost
                    ? __('messages.host.no_capacity')
                    : __('messages.host.no_active_host');

                throw ValidationException::withMessages([
                    'host_id' => [$errorMsg],
                ]);
            }
            $hostId = $chosenHost->id;
        } else {
            $chosenHost = Host::find($hostId);
            if ($chosenHost === null) {
                throw ValidationException::withMessages([
                    'host_id' => [__('messages.host.not_active_or_available')],
                ]);
            }

            $this->hostSelector->validateHost($chosenHost, [
                'os_image' => $data['os_image'],
                'instance_type' => $instanceType,
                'cpu_cores' => $data['cpu_cores'],
                'ram_mb' => $data['ram_mb'],
                'disk_gb' => $data['disk_gb'],
            ]);
        }

        // Acquire distributed lock to eliminate row lock contention across concurrent requests
        $lock = Cache::lock(sprintf('user_quota_lock:%d', $user->id), 10);

        try {
            /** @var VirtualMachine $provisionedVm */
            $provisionedVm = $lock->block(5, function () use ($user, $data, $instanceType, $hostId): VirtualMachine {
                return DB::transaction(function () use ($user, $data, $instanceType, $hostId): VirtualMachine {
                    // Lock quota row to prevent race conditions during concurrent requests
                    $quota = UserQuota::firstOrCreate(
                        ['user_id' => $user->id],
                        SystemSetting::getDefaultQuota()
                    );

                    // Re-fetch with lock
                    /** @var UserQuota $lockedQuota */
                    $lockedQuota = UserQuota::where('id', $quota->id)->lockForUpdate()->firstOrFail();

                    // Calculate active resource usage
                    /** @var object{cpu: int|float|string, ram: int|float|string, disk: int|float|string, vms: int|string} $usage */
                    $usage = VirtualMachine::where('user_id', $user->id)
                        ->whereNotIn('status', ['failed', 'deleted'])
                        ->selectRaw('COALESCE(SUM(cpu_cores), 0) as cpu,
                                     COALESCE(SUM(ram_mb), 0) as ram,
                                     COALESCE(SUM(disk_gb), 0) as disk,
                                     COUNT(*) as vms')
                        ->firstOrFail();

                    $currentVms = (int) $usage->vms;
                    $currentCpu = (int) $usage->cpu;
                    $currentRam = (int) $usage->ram;
                    $currentDisk = (int) $usage->disk;

                    // Enforce quota limits (values of -1 represent infinite / unlimited quota)
                    if ($lockedQuota->max_vms !== -1 && $currentVms + 1 > $lockedQuota->max_vms) {
                        throw ValidationException::withMessages([
                            'quota' => [__('messages.quota.vms_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_cpu_cores !== -1 && $currentCpu + $data['cpu_cores'] > $lockedQuota->max_cpu_cores) {
                        throw ValidationException::withMessages([
                            'cpu_cores' => [__('messages.quota.cpu_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_ram_mb !== -1 && $currentRam + $data['ram_mb'] > $lockedQuota->max_ram_mb) {
                        throw ValidationException::withMessages([
                            'ram_mb' => [__('messages.quota.ram_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_storage_gb !== -1 && $currentDisk + $data['disk_gb'] > $lockedQuota->max_storage_gb) {
                        throw ValidationException::withMessages([
                            'disk_gb' => [__('messages.quota.storage_exceeded')],
                        ]);
                    }

                    // Check public IP quota (values of -1 represent infinite / unlimited quota)
                    $currentIps = IpAllocation::where('user_id', $user->id)
                        ->where('status', 'allocated')
                        ->count();
                    if ($lockedQuota->max_ips !== -1 && $currentIps + 1 > $lockedQuota->max_ips) {
                        throw ValidationException::withMessages([
                            'ip_quota' => [__('messages.quota.ips_exceeded')],
                        ]);
                    }

                    // LOCK ORDER:
                    // 1. user quota lock (distributed cache lock: user_quota_lock:{id})
                    // 2. user quota row (UserQuota lockForUpdate)
                    // 3. host row (Host lockForUpdate)
                    // No other code path in the system acquires these locks in reverse order.
                    /** @var Host $lockedHost */
                    $lockedHost = Host::where('id', $hostId)->lockForUpdate()->firstOrFail();

                    if (! $lockedHost->is_active || $lockedHost->status !== 'ok') {
                        throw ValidationException::withMessages([
                            'host_id' => [__('messages.host.not_active_or_available')],
                        ]);
                    }

                    $duplicateActiveName = VirtualMachine::where('user_id', $user->id)
                        ->where('host_id', $hostId)
                        ->where('name', $data['name'])
                        ->where('status', '!=', 'deleted')
                        ->lockForUpdate()
                        ->exists();

                    if ($duplicateActiveName) {
                        throw ValidationException::withMessages([
                            'name' => [__('validation.unique', ['attribute' => 'name'])],
                        ]);
                    }

                    // Authoritative capacity check under host row lock
                    $this->capacityGuard->check($lockedHost, $data['cpu_cores'], $data['ram_mb'], $data['disk_gb']);

                    // Iterate the host's active pools ordered by id and take the first one that has a free address
                    $activePools = IpPool::where('host_id', $hostId)
                        ->where('active', true)
                        ->orderBy('id', 'asc')
                        ->get();

                    $this->ipService->attachUtilizationToPools($activePools);

                    $pool = null;
                    foreach ($activePools as $poolCandidate) {
                        /** @var array{total: int, allocated: int, cooling: int, free: int, percent: float}|null $util */
                        $util = $poolCandidate->utilization;
                        if ($util !== null && $util['free'] > 0) {
                            $pool = $poolCandidate;
                            break;
                        }
                    }

                    if ($pool === null) {
                        throw ValidationException::withMessages([
                            'ip_pool' => ['no free IP on this compute node (No available IP addresses in pool)'],
                        ]);
                    }

                    // Tenant isolation project name per user (UUID-based project assigned to user)
                    $incusProject = $user->ensureProjectName();

                    /** @var VirtualMachine $vm */
                    $vm = VirtualMachine::create([
                        'user_id' => $user->id,
                        'host_id' => $hostId,
                        'name' => $data['name'],
                        'incus_project' => $incusProject,
                        'instance_type' => $instanceType,
                        'status' => Provisioning::class,
                        'network_status' => 'assigned',
                        'cpu_cores' => $data['cpu_cores'],
                        'ram_mb' => $data['ram_mb'],
                        'disk_gb' => $data['disk_gb'],
                        'os_image' => $data['os_image'],
                        'cloud_init' => $data['cloud_init'] ?? null,
                    ]);

                    $spec = [
                        'name' => $data['name'],
                        'instance_type' => $instanceType,
                        'cpu_cores' => $data['cpu_cores'],
                        'ram_mb' => $data['ram_mb'],
                        'disk_gb' => $data['disk_gb'],
                        'os_image' => $data['os_image'],
                        'host_id' => $hostId,
                    ];

                    $this->logger()->record(new AuditEntry(
                        action: AuditAction::VmCreateRequested,
                        outcome: AuditOutcome::Success,
                        subject: $vm,
                        tenantUserId: $vm->user_id,
                        visibility: 'tenant',
                        context: ['spec' => $spec],
                    ));

                    // Dynamically allocate IP address synchronously from the host's pool
                    try {
                        $allocation = $this->ipService->allocateIp($pool, $vm, $user);
                    } catch (IpPoolExhaustedException $e) {
                        throw ValidationException::withMessages([
                            'ip_pool' => ['no free IP on this compute node (No available IP addresses in pool)'],
                        ]);
                    }

                    // Dispatch async creation job after database transaction commits
                    ProvisionVmJob::dispatch($vm, $allocation->id)->afterCommit();

                    return $vm->fresh(['primaryAllocation', 'host']) ?? $vm;
                });
            });

            return $provisionedVm;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'quota' => ['A provisioning request is already being processed for your account. Please retry in a moment.'],
            ]);
        }
    }
}
