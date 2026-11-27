<?php

declare(strict_types=1);

namespace App\Services\Vps;

use App\DTO\Cast;
use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\NetworkLimitsSpec;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateVerdict;
use App\Exceptions\DriverUnavailableException;
use App\Exceptions\NoHypervisorAvailableException;
use App\Models\Host;
use App\Models\IpAllocation;
use App\Models\IpPool;
use App\Models\SystemSetting;
use App\Models\UserQuota;
use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use App\Services\HostCapacityGuard;
use App\Services\Hypervisor\HypervisorManager;
use App\States\Host\Ok as HostOk;
use App\States\Vm\Running as VmRunning;
use App\States\Vm\Stopped as VmStopped;
use App\States\VpsPlan\Archived as PlanArchived;
use App\States\VpsPlan\Published as PlanPublished;
use App\States\VpsPlanChange\VpsPlanChangeState;
use App\States\VpsPlanVersion\Draft as VersionDraft;
use App\States\VpsPlanVersion\Published as VersionPublished;

/**
 * Shared preflight validator evaluating whether a virtual machine can transition
 * to a target VPS plan version (used by targets, preview, and request flows).
 */
final class VpsPlanChangeValidator
{
    public function __construct(
        private readonly HostCapacityGuard $capacityGuard = new HostCapacityGuard,
        private readonly ResourceReservationService $reservationService = new ResourceReservationService,
        private readonly HypervisorManager $hypervisorManager = new HypervisorManager,
    ) {}

    /**
     * Evaluate plan-change eligibility against the VM's actual columns, quotas, capacity,
     * IP pool networking mode, and (unless skipped) hypervisor driver capability assessment.
     *
     * @return array{
     *     can_submit: bool,
     *     requires_shutdown: bool,
     *     changes: array{
     *         cpu: string,
     *         ram: string,
     *         disk: string,
     *         net_download: string,
     *         net_upload: string,
     *     },
     *     blocking_reasons: list<array{code: string, message: string, kind?: string}>,
     *     warnings: list<string>,
     * }
     */
    public function validate(
        VirtualMachine $vm,
        VpsPlanVersion $targetVersion,
        bool $isAdmin = false,
        bool $skipDriverAssessment = false,
        ?int $excludeChangeId = null,
        bool $useLocks = false,
    ): array {
        /** @var list<array{code: string, message: string, kind?: string}> $blockingReasons */
        $blockingReasons = [];
        /** @var list<string> $warnings */
        $warnings = [];
        $requiresShutdown = false;

        $changes = [
            ResourceKind::Cpu->value => $this->compareInt((int) $vm->cpu_cores, (int) $targetVersion->cpu_cores),
            ResourceKind::Ram->value => $this->compareInt((int) $vm->ram_mb, (int) $targetVersion->ram_mb),
            ResourceKind::Disk->value => $this->compareInt((int) $vm->disk_gb, (int) $targetVersion->disk_gb),
            ResourceKind::NetDownload->value => $this->compareNullableInt($vm->net_download_mbps, $targetVersion->net_download_mbps),
            ResourceKind::NetUpload->value => $this->compareNullableInt($vm->net_upload_mbps, $targetVersion->net_upload_mbps),
        ];

        if ((int) $targetVersion->disk_gb > (int) $vm->disk_gb) {
            $warnings[] = 'DISK_GUEST_RESIZE_MAY_BE_REQUIRED';
        }

        // 1. VM lifecycle state check
        $vmStateSupported = $vm->status->equals(VmRunning::class) || $vm->status->equals(VmStopped::class);
        if (! $vmStateSupported) {
            $blockingReasons[] = [
                'code' => 'VM_STATE_NOT_SUPPORTED',
                'message' => 'Plan changes are only supported when the virtual machine is running or stopped.',
            ];
        }

        // 2. Single active plan change per VM
        $activeChangeQuery = VpsPlanChange::query()
            ->where('virtual_machine_id', $vm->id)
            ->whereIn('status', VpsPlanChangeState::activeValues());

        if ($excludeChangeId !== null) {
            $activeChangeQuery->where('id', '!=', $excludeChangeId);
        }

        if ($activeChangeQuery->exists()) {
            $blockingReasons[] = [
                'code' => 'ANOTHER_CHANGE_IN_PROGRESS',
                'message' => 'Another plan change is already in progress for this virtual machine.',
            ];
        }

        // 3 & 4. Plan and version availability
        /** @var VpsPlan|null $plan */
        $plan = $targetVersion->relationLoaded('vpsPlan')
            ? $targetVersion->getRelation('vpsPlan')
            : $targetVersion->vpsPlan()->first();

        if (! $isAdmin) {
            if ($plan === null || ! $plan->status->equals(PlanPublished::class)) {
                $blockingReasons[] = [
                    'code' => 'PLAN_NOT_AVAILABLE',
                    'message' => 'The selected VPS plan is not available.',
                ];
            } elseif (! $targetVersion->status->equals(VersionPublished::class) || $plan->current_version_id !== $targetVersion->id) {
                $blockingReasons[] = [
                    'code' => 'VERSION_NOT_AVAILABLE',
                    'message' => 'The selected VPS plan version is not currently published.',
                ];
            }
        } elseif ($plan === null || $plan->status->equals(PlanArchived::class) || ! $plan->status->equals(PlanPublished::class)) {
            $blockingReasons[] = [
                'code' => 'VERSION_NOT_AVAILABLE',
                'message' => 'The selected VPS plan version does not belong to an active published plan.',
            ];
        } elseif ($targetVersion->status->equals(VersionDraft::class)) {
            $blockingReasons[] = [
                'code' => 'VERSION_NOT_AVAILABLE',
                'message' => 'Draft VPS plan versions cannot be applied to a virtual machine.',
            ];
        }

        // 5. No-op change check against actual VM columns and current plan version ID
        $profileMatchesActual = (int) $vm->cpu_cores === (int) $targetVersion->cpu_cores
            && (int) $vm->ram_mb === (int) $targetVersion->ram_mb
            && (int) $vm->disk_gb === (int) $targetVersion->disk_gb
            && (string) $vm->instance_type === (string) $targetVersion->instance_type
            && $vm->net_download_mbps === $targetVersion->net_download_mbps
            && $vm->net_upload_mbps === $targetVersion->net_upload_mbps;

        $isNoChange = $profileMatchesActual && $vm->vps_plan_version_id === $targetVersion->id;
        if ($isNoChange) {
            $blockingReasons[] = [
                'code' => 'NO_CHANGE',
                'message' => 'The virtual machine already matches the target plan version.',
            ];
        }

        // 6. Instance type compatibility
        $instanceTypeMismatch = (string) $targetVersion->instance_type !== (string) $vm->instance_type;
        if ($instanceTypeMismatch) {
            $blockingReasons[] = [
                'code' => 'INSTANCE_TYPE_MISMATCH',
                'message' => 'Cannot change between virtual-machine and container instance types.',
            ];
        }

        // 7. Disk shrink prohibition
        $isDiskShrink = (int) $targetVersion->disk_gb < (int) $vm->disk_gb;
        if ($isDiskShrink) {
            $blockingReasons[] = [
                'code' => 'DISK_SHRINK_NOT_SUPPORTED',
                'message' => 'Shrinking root disk storage is not supported.',
            ];
        }

        // 8. Tenant resource quota check (Spec 9.3)
        $this->checkUserQuotas($vm, $targetVersion, $excludeChangeId, $blockingReasons, $useLocks);

        // 9 & 11. Host reachability and capacity check
        /** @var Host|null $host */
        $host = $vm->relationLoaded('host')
            ? $vm->getRelation('host')
            : $vm->host()->first();

        $hostAvailableInDb = $host !== null && $host->is_active && $host->status->equals(HostOk::class);
        if (! $hostAvailableInDb) {
            $blockingReasons[] = [
                'code' => 'HOST_UNREACHABLE',
                'message' => Cast::string(__('messages.host.not_active_or_available')),
            ];
        }

        $targetNetwork = ($targetVersion->net_download_mbps !== null || $targetVersion->net_upload_mbps !== null)
            ? new NetworkLimitsSpec(
                downloadMbps: $targetVersion->net_download_mbps,
                uploadMbps: $targetVersion->net_upload_mbps,
            )
            : null;

        $targetSpec = new InstanceResourceSpec(
            cpuCores: (int) $targetVersion->cpu_cores,
            ramMb: (int) $targetVersion->ram_mb,
            diskGb: (int) $targetVersion->disk_gb,
            instanceType: (string) $targetVersion->instance_type,
            network: $targetNetwork,
        );

        if ($host !== null) {
            $capacityResult = $this->capacityGuard->checkChange($host, $vm, $targetSpec, $excludeChangeId, lock: $useLocks);
            if (! $capacityResult->allowed) {
                $blockingReasons[] = [
                    'code' => 'HOST_CAPACITY_EXCEEDED',
                    'message' => $capacityResult->message ?? Cast::string(__('messages.host.insufficient_capacity')),
                ];
            }
        }

        // 10. Manual IP pool network-limit restriction
        if ($targetNetwork !== null) {
            /** @var IpAllocation|null $primaryAllocation */
            $primaryAllocation = $vm->relationLoaded('primaryAllocation')
                ? $vm->getRelation('primaryAllocation')
                : $vm->primaryAllocation()->with('pool')->first();

            /** @var IpPool|null $pool */
            $pool = $primaryAllocation !== null
                ? ($primaryAllocation->relationLoaded('pool')
                    ? $primaryAllocation->getRelation('pool')
                    : $primaryAllocation->pool()->first())
                : null;

            if ($pool !== null && $pool->network_mode === 'manual') {
                $blockingReasons[] = [
                    'code' => 'NETWORK_LIMIT_NOT_SUPPORTED_MODE',
                    'message' => 'Network bandwidth limits are not supported when the instance uses a manual-mode IP pool.',
                ];
            }
        }

        // 12. Hypervisor driver capability assessment
        if (! $skipDriverAssessment && $hostAvailableInDb && $vmStateSupported && ! $instanceTypeMismatch && ! $isNoChange) {
            try {
                $driver = $this->hypervisorManager->driverForVm($vm);
                $assessment = $driver->assessResourceUpdate($vm, $targetSpec);

                if ($assessment->requiresStop()) {
                    $requiresShutdown = true;
                    $blockingReasons[] = [
                        'code' => 'REQUIRES_SHUTDOWN_NOT_SUPPORTED',
                        'message' => 'This resource change requires stopping the instance first.',
                    ];
                }

                $addedNoNic = false;
                foreach ($assessment->verdicts as $kind => $verdict) {
                    if ($verdict !== ResourceUpdateVerdict::Unsupported) {
                        continue;
                    }

                    $reason = $assessment->reasons[$kind] ?? null;

                    if ($reason === 'DISK_SHRINK' && $isDiskShrink) {
                        continue;
                    }

                    if ($reason === 'NETWORK_LIMIT_NO_NIC') {
                        if (! $addedNoNic) {
                            $addedNoNic = true;
                            $blockingReasons[] = [
                                'code' => 'NETWORK_LIMIT_NO_NIC',
                                'message' => 'Network bandwidth limits require a local NIC device on the instance.',
                            ];
                        }

                        continue;
                    }

                    $blockingReasons[] = [
                        'code' => 'DRIVER_OPERATION_UNSUPPORTED',
                        'kind' => $kind,
                        'message' => $reason !== null && $reason !== ''
                            ? sprintf('Hypervisor driver does not support updating %s (%s).', $kind, $reason)
                            : sprintf('Hypervisor driver does not support updating %s.', $kind),
                    ];
                }
            } catch (DriverUnavailableException|NoHypervisorAvailableException) {
                $blockingReasons[] = [
                    'code' => 'HOST_UNREACHABLE',
                    'message' => 'The compute host is currently unreachable.',
                ];
            }
        }

        return [
            'can_submit' => $blockingReasons === [],
            'requires_shutdown' => $requiresShutdown,
            'changes' => $changes,
            'blocking_reasons' => $blockingReasons,
            'warnings' => $warnings,
        ];
    }

    /**
     * Evaluate per-resource user quota limits for increasing resources, including active reservations.
     *
     * @param  list<array{code: string, message: string, kind?: string}>  $blockingReasons
     */
    private function checkUserQuotas(
        VirtualMachine $vm,
        VpsPlanVersion $targetVersion,
        ?int $excludeChangeId,
        array &$blockingReasons,
        bool $useLocks = false,
    ): void {
        $cpuDelta = max(0, (int) $targetVersion->cpu_cores - (int) $vm->cpu_cores);
        $ramDelta = max(0, (int) $targetVersion->ram_mb - (int) $vm->ram_mb);
        $diskDelta = max(0, (int) $targetVersion->disk_gb - (int) $vm->disk_gb);

        if ($cpuDelta === 0 && $ramDelta === 0 && $diskDelta === 0) {
            return;
        }

        /** @var object{cpu: int|float|string|null, ram: int|float|string|null, disk: int|float|string|null}|null $usage */
        $usage = VirtualMachine::query()
            ->where('user_id', $vm->user_id)
            ->whereNotIn('status', ['failed', 'deleted'])
            ->selectRaw('COALESCE(SUM(cpu_cores), 0) as cpu, COALESCE(SUM(ram_mb), 0) as ram, COALESCE(SUM(disk_gb), 0) as disk')
            ->first();

        $reservations = $this->reservationService->getUserReservations($vm->user_id, $excludeChangeId, lock: $useLocks);

        /** @var UserQuota|null $userQuota */
        $userQuota = UserQuota::query()->where('user_id', $vm->user_id)->first();
        $defaults = SystemSetting::getDefaultQuota();

        $checks = [
            [
                'kind' => ResourceKind::Cpu->value,
                'delta' => $cpuDelta,
                'used' => (int) ($usage->cpu ?? 0) + $reservations['cpu'],
                'max' => $userQuota !== null ? (int) $userQuota->max_cpu_cores : $defaults['max_cpu_cores'],
                'message' => Cast::string(__('messages.quota.cpu_exceeded')),
            ],
            [
                'kind' => ResourceKind::Ram->value,
                'delta' => $ramDelta,
                'used' => (int) ($usage->ram ?? 0) + $reservations['ram'],
                'max' => $userQuota !== null ? (int) $userQuota->max_ram_mb : $defaults['max_ram_mb'],
                'message' => Cast::string(__('messages.quota.ram_exceeded')),
            ],
            [
                'kind' => ResourceKind::Disk->value,
                'delta' => $diskDelta,
                'used' => (int) ($usage->disk ?? 0) + $reservations['disk'],
                'max' => $userQuota !== null ? (int) $userQuota->max_storage_gb : $defaults['max_storage_gb'],
                'message' => Cast::string(__('messages.quota.storage_exceeded')),
            ],
        ];

        foreach ($checks as $check) {
            if ($check['delta'] > 0 && $check['max'] !== -1 && ($check['used'] + $check['delta']) > $check['max']) {
                $blockingReasons[] = [
                    'code' => 'QUOTA_EXCEEDED',
                    'kind' => $check['kind'],
                    'message' => $check['message'],
                ];
            }
        }
    }

    private function compareInt(int $current, int $target): string
    {
        if ($target > $current) {
            return 'increase';
        }

        if ($target < $current) {
            return 'decrease';
        }

        return 'unchanged';
    }

    private function compareNullableInt(?int $current, ?int $target): string
    {
        if ($current === null) {
            return $target === null ? 'unchanged' : 'set';
        }

        if ($target === null) {
            return 'remove';
        }

        return $this->compareInt($current, $target);
    }
}
