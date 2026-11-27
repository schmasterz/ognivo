<?php

declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Contracts\Data\VpsAuthorizationInitiator;
use App\Contracts\Data\VpsProvisioningAuthorizationRequest;
use App\Contracts\Data\VpsProvisioningAuthorizationResult;
use App\Contracts\VpsProvisioningAuthorizerInterface;
use App\Enums\IpAllocationStatus;
use App\Enums\NetworkStatus;
use App\Enums\Vps\VpsAuthorizationStatus;
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
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use App\Services\Vps\ResourceReservationService;
use App\States\Host\Ok as HostOk;
use App\States\User\Active as UserActive;
use App\States\Vm\Provisioning;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanVersion\Published as VersionPublished;
use App\Support\Cast;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

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
        private readonly ResourceReservationService $reservationService = new ResourceReservationService,
        private readonly ?VpsProvisioningAuthorizerInterface $authorizer = null,
    ) {}

    private function logger(): AuditLogger
    {
        return $this->auditLogger ?? app(AuditLogger::class);
    }

    private function authorizer(): VpsProvisioningAuthorizerInterface
    {
        return $this->authorizer ?? app(VpsProvisioningAuthorizerInterface::class);
    }

    /**
     * Provision a new virtual machine or container under strict quota constraints and dynamic IPAM.
     *
     * @param array{
     *     name: string,
     *     cpu_cores?: int|null,
     *     ram_mb?: int|null,
     *     disk_gb?: int|null,
     *     os_image: string,
     *     instance_type?: string|null,
     *     host_id?: int|null,
     *     cloud_init?: array<string, mixed>|null,
     *     vps_plan_id?: int|null
     * } $data
     *
     * @throws ValidationException
     */
    public function provision(User $user, array $data): VirtualMachine
    {
        if (! $user->status->equals(UserActive::class)) {
            throw ValidationException::withMessages([
                'user' => [__($user->status->inactiveMessageKey())],
            ]);
        }

        // 1. Resolve plan snapshot or custom resource specifications
        $vpsPlanId = $data['vps_plan_id'] ?? null;
        $vpsPlanVersion = null;
        $vpsPlanVersionId = null;
        $netDownloadMbps = null;
        $netUploadMbps = null;

        if ($vpsPlanId !== null) {
            /** @var VpsPlan|null $plan */
            $plan = VpsPlan::with('currentVersion')->find($vpsPlanId);
            if ($plan === null || ! $plan->status->equals(PlanPublished::class)) {
                throw $this->validationExceptionWithCode(
                    'vps_plan_id',
                    'The selected VPS plan is not available.',
                    'PLAN_NOT_AVAILABLE',
                );
            }

            /** @var VpsPlanVersion|null $version */
            $version = $plan->currentVersion;
            if ($version === null || ! $version->status->equals(VersionPublished::class)) {
                throw $this->validationExceptionWithCode(
                    'vps_plan_id',
                    'The selected VPS plan does not have a published version available.',
                    'VERSION_NOT_AVAILABLE',
                );
            }

            $cpuCores = (int) $version->cpu_cores;
            $ramMb = (int) $version->ram_mb;
            $diskGb = (int) $version->disk_gb;
            $instanceType = (string) $version->instance_type;
            $netDownloadMbps = $version->net_download_mbps;
            $netUploadMbps = $version->net_upload_mbps;
            $vpsPlanVersion = $version;
            $vpsPlanVersionId = $version->id;
        } else {
            $cpuCores = Cast::int($data['cpu_cores'] ?? 0);
            $ramMb = Cast::int($data['ram_mb'] ?? 0);
            $diskGb = Cast::int($data['disk_gb'] ?? 0);
            $rawInstanceType = $data['instance_type'] ?? null;
            $instanceType = is_string($rawInstanceType) && $rawInstanceType !== ''
                ? $rawInstanceType
                : (SystemSetting::isVmEnabled() ? 'virtual-machine' : 'container');
        }

        $requiresNetworkLimits = ($netDownloadMbps !== null || $netUploadMbps !== null);

        // 2. Validate platform virtualization mode support
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

        // 3. Enforce admin-approved image allowlist
        $allowedImage = Image::where('name', $data['os_image'])->where('enabled', true)->first();
        if ($allowedImage === null) {
            throw ValidationException::withMessages([
                'os_image' => ['The selected image is not in the allowlist or is disabled.'],
            ]);
        }

        // 4. Enforce that containers (LXC) can only run Linux images
        if ($instanceType === 'container' && $allowedImage->os_type !== 'linux') {
            throw ValidationException::withMessages([
                'instance_type' => ['Containers (LXC) can only run Linux operating system images.'],
            ]);
        }

        // 5. Optimistic Host selection / validation before entering transaction
        $hostId = $data['host_id'] ?? null;
        if ($hostId === null) {
            $chosenHost = $this->hostSelector->select([
                'os_image' => $data['os_image'],
                'instance_type' => $instanceType,
                'cpu_cores' => $cpuCores,
                'ram_mb' => $ramMb,
                'disk_gb' => $diskGb,
                'requires_network_limits' => $requiresNetworkLimits,
            ]);

            if ($chosenHost === null) {
                if ($requiresNetworkLimits) {
                    $hostWithoutLimits = $this->hostSelector->select([
                        'os_image' => $data['os_image'],
                        'instance_type' => $instanceType,
                        'cpu_cores' => $cpuCores,
                        'ram_mb' => $ramMb,
                        'disk_gb' => $diskGb,
                        'requires_network_limits' => false,
                    ]);

                    if ($hostWithoutLimits !== null) {
                        throw $this->validationExceptionWithCode(
                            'ip_pool',
                            'Network bandwidth limits are not supported on hosts with only manual-mode IP pools.',
                            'NETWORK_LIMIT_NOT_SUPPORTED_MODE',
                        );
                    }
                }

                $hasAnyActiveHost = Host::where('is_active', true)->where('status', HostOk::$name)->exists();
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
                'cpu_cores' => $cpuCores,
                'ram_mb' => $ramMb,
                'disk_gb' => $diskGb,
                'requires_network_limits' => $requiresNetworkLimits,
            ]);
        }

        if ($vpsPlanVersion !== null) {
            $authRequest = new VpsProvisioningAuthorizationRequest(
                requestId: strtolower((string) Str::ulid()),
                userId: (int) $user->id,
                toVersionId: (int) $vpsPlanVersion->id,
                initiator: new VpsAuthorizationInitiator(
                    id: (int) $user->id,
                    isAdmin: $user->hasRole('admin'),
                ),
                virtualMachineId: null,
                fromVersionId: null,
                oldResources: [],
                newResources: [
                    'cpu_cores' => $cpuCores,
                    'ram_mb' => $ramMb,
                    'disk_gb' => $diskGb,
                    'net_download_mbps' => $netDownloadMbps !== null ? (int) $netDownloadMbps : null,
                    'net_upload_mbps' => $netUploadMbps !== null ? (int) $netUploadMbps : null,
                ],
            );

            $authResult = $this->authorizeProvisioning($authRequest);

            if ($authResult->status !== VpsAuthorizationStatus::Allowed) {
                $message = ($authResult->safeMessage !== null && trim($authResult->safeMessage) !== '')
                    ? trim($authResult->safeMessage)
                    : ($authResult->status === VpsAuthorizationStatus::Pending
                        ? 'Pending authorization is not supported for synchronous VPS provisioning.'
                        : 'Provisioning request was denied by the authorization provider.');

                throw $this->validationExceptionWithCode(
                    'vps_plan_id',
                    $message,
                    'EXTENSION_AUTHORIZATION_DENIED',
                );
            }
        }

        // Acquire distributed lock to eliminate row lock contention across concurrent requests
        $lock = Cache::lock(sprintf('user_quota_lock:%d', $user->id), 10);

        try {
            /** @var VirtualMachine $provisionedVm */
            $provisionedVm = $lock->block(5, function () use (
                $user,
                $data,
                $instanceType,
                $hostId,
                $cpuCores,
                $ramMb,
                $diskGb,
                $vpsPlanVersionId,
                $netDownloadMbps,
                $netUploadMbps,
                $requiresNetworkLimits,
            ): VirtualMachine {
                return DB::transaction(function () use (
                    $user,
                    $data,
                    $instanceType,
                    $hostId,
                    $cpuCores,
                    $ramMb,
                    $diskGb,
                    $vpsPlanVersionId,
                    $netDownloadMbps,
                    $netUploadMbps,
                    $requiresNetworkLimits,
                ): VirtualMachine {
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

                    $reservations = $this->reservationService->getUserReservations($user->id);

                    $currentVms = (int) $usage->vms;
                    $currentCpu = (int) $usage->cpu + $reservations['cpu'];
                    $currentRam = (int) $usage->ram + $reservations['ram'];
                    $currentDisk = (int) $usage->disk + $reservations['disk'];

                    // Enforce quota limits (values of -1 represent infinite / unlimited quota)
                    if ($lockedQuota->max_vms !== -1 && $currentVms + 1 > $lockedQuota->max_vms) {
                        throw ValidationException::withMessages([
                            'quota' => [__('messages.quota.vms_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_cpu_cores !== -1 && $currentCpu + $cpuCores > $lockedQuota->max_cpu_cores) {
                        throw ValidationException::withMessages([
                            'cpu_cores' => [__('messages.quota.cpu_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_ram_mb !== -1 && $currentRam + $ramMb > $lockedQuota->max_ram_mb) {
                        throw ValidationException::withMessages([
                            'ram_mb' => [__('messages.quota.ram_exceeded')],
                        ]);
                    }

                    if ($lockedQuota->max_storage_gb !== -1 && $currentDisk + $diskGb > $lockedQuota->max_storage_gb) {
                        throw ValidationException::withMessages([
                            'disk_gb' => [__('messages.quota.storage_exceeded')],
                        ]);
                    }

                    // Check public IP quota (values of -1 represent infinite / unlimited quota)
                    $currentIps = IpAllocation::where('user_id', $user->id)
                        ->where('status', IpAllocationStatus::Allocated->value)
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

                    if (! $lockedHost->is_active || ! $lockedHost->status->equals(HostOk::class)) {
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
                    $this->capacityGuard->check($lockedHost, $cpuCores, $ramMb, $diskGb);

                    // Iterate the host's active pools ordered by id and take the first suitable one with a free address
                    $activePools = IpPool::where('host_id', $hostId)
                        ->where('active', true)
                        ->orderBy('id', 'asc')
                        ->get();

                    $this->ipService->attachUtilizationToPools($activePools);

                    $pool = null;
                    $hasManualPoolWithFreeIps = false;
                    foreach ($activePools as $poolCandidate) {
                        /** @var array{total: int, allocated: int, cooling: int, free: int, percent: float}|null $util */
                        $util = $poolCandidate->utilization;
                        if ($util === null || $util['free'] <= 0) {
                            continue;
                        }

                        if ($requiresNetworkLimits && $poolCandidate->network_mode === 'manual') {
                            $hasManualPoolWithFreeIps = true;

                            continue;
                        }

                        $pool = $poolCandidate;
                        break;
                    }

                    if ($pool === null) {
                        if ($requiresNetworkLimits && $hasManualPoolWithFreeIps) {
                            throw $this->validationExceptionWithCode(
                                'ip_pool',
                                'Network bandwidth limits are not supported by manual-mode IP pools.',
                                'NETWORK_LIMIT_NOT_SUPPORTED_MODE',
                            );
                        }

                        throw ValidationException::withMessages([
                            'ip_pool' => ['no free IP on this compute node (No available IP addresses in pool)'],
                        ]);
                    }

                    // Tenant isolation project name per user (UUID-based project assigned to user)
                    $incusProject = $user->ensureProjectName();

                    $vm = new VirtualMachine([
                        'user_id' => $user->id,
                        'host_id' => $hostId,
                        'name' => $data['name'],
                        'incus_project' => $incusProject,
                        'instance_type' => $instanceType,
                        'status' => Provisioning::class,
                        'network_status' => NetworkStatus::Assigned,
                        'cpu_cores' => $cpuCores,
                        'ram_mb' => $ramMb,
                        'disk_gb' => $diskGb,
                        'os_image' => $data['os_image'],
                        'cloud_init' => $data['cloud_init'] ?? null,
                    ]);
                    $vm->vps_plan_version_id = $vpsPlanVersionId;
                    $vm->net_download_mbps = $netDownloadMbps;
                    $vm->net_upload_mbps = $netUploadMbps;
                    $vm->save();

                    $spec = [
                        'name' => $data['name'],
                        'instance_type' => $instanceType,
                        'cpu_cores' => $cpuCores,
                        'ram_mb' => $ramMb,
                        'disk_gb' => $diskGb,
                        'os_image' => $data['os_image'],
                        'host_id' => $hostId,
                        'vps_plan_version_id' => $vpsPlanVersionId,
                        'net_download_mbps' => $netDownloadMbps,
                        'net_upload_mbps' => $netUploadMbps,
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

                    return $vm->fresh(['primaryAllocation.pool', 'host', 'vpsPlanVersion.vpsPlan']) ?? $vm;
                });
            });

            return $provisionedVm;
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'quota' => ['A provisioning request is already being processed for your account. Please retry in a moment.'],
            ]);
        }
    }

    /**
     * Invoke the provisioning authorizer outside any database transaction or lock, bounded by the configured timeout.
     */
    private function authorizeProvisioning(VpsProvisioningAuthorizationRequest $authRequest): VpsProvisioningAuthorizationResult
    {
        $timeoutSeconds = max(1, Cast::int(config('vps.authorizer_timeout_seconds', 3), 3));
        $startedAtNs = hrtime(true);
        $hasPcntl = function_exists('pcntl_alarm')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal_get_handler');

        $previousAsync = false;
        $previousHandler = SIG_DFL;

        if ($hasPcntl) {
            $previousAsync = pcntl_async_signals(true);
            $previousHandler = pcntl_signal_get_handler(SIGALRM);
            pcntl_signal(SIGALRM, static function (): never {
                throw new RuntimeException('Provisioning authorizer timed out.');
            });
            pcntl_alarm($timeoutSeconds);
        }

        try {
            $result = $this->authorizer()->authorize($authRequest);
            $elapsedSeconds = (hrtime(true) - $startedAtNs) / 1_000_000_000;
            if ($elapsedSeconds > (float) $timeoutSeconds) {
                throw new RuntimeException('Provisioning authorizer exceeded configured timeout.');
            }

            return $result;
        } catch (Throwable $e) {
            throw new class('External authorization service is currently unavailable. (EXTENSION_AUTHORIZATION_UNAVAILABLE)', $e) extends HttpException
            {
                public function __construct(string $message, ?Throwable $previous = null)
                {
                    parent::__construct(503, $message, $previous);
                }

                public function render(): JsonResponse
                {
                    return response()->json([
                        'message' => $this->getMessage(),
                        'code' => 'EXTENSION_AUTHORIZATION_UNAVAILABLE',
                    ], 503);
                }
            };
        } finally {
            if ($hasPcntl) {
                pcntl_alarm(0);
                if ($previousHandler === SIG_IGN) {
                    pcntl_signal(SIGALRM, SIG_IGN);
                } elseif (is_callable($previousHandler)) {
                    pcntl_signal(SIGALRM, $previousHandler);
                } else {
                    pcntl_signal(SIGALRM, SIG_DFL);
                }
                pcntl_async_signals($previousAsync);
            }
        }
    }

    /**
     * Build a ValidationException with a structured JSON error code for HTTP 422 responses.
     */
    private function validationExceptionWithCode(string $field, string $message, string $code): ValidationException
    {
        $exception = ValidationException::withMessages([
            $field => [$message],
        ]);
        $exception->response = response()->json([
            'message' => $message,
            'code' => $code,
            'errors' => $exception->errors(),
        ], 422);

        return $exception;
    }
}
