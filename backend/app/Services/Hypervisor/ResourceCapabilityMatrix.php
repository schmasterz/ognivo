<?php

declare(strict_types=1);

namespace App\Services\Hypervisor;

use App\DTO\Hypervisor\InstanceResourceSpec;
use App\DTO\Hypervisor\ResourceKind;
use App\DTO\Hypervisor\ResourceUpdateAssessment;
use App\DTO\Hypervisor\ResourceUpdateVerdict;

/**
 * Data-driven conservative capability matrix for evaluating whether an instance
 * resource change can be applied online, requires a stop, or is unsupported.
 */
final class ResourceCapabilityMatrix
{
    /**
     * Storage pool drivers confirmed to enforce container root disk quotas.
     *
     * @var list<string>
     */
    private const array QUOTA_STORAGE_DRIVERS = [
        'zfs',
        'btrfs',
        'lvm',
        'lvmcluster',
        'ceph',
    ];

    /**
     * Incus/LXD NIC device `nictype` values confirmed to support live ingress/egress rate limits.
     *
     * @var list<string>
     */
    private const array CONFIRMED_NIC_TYPES = [
        'routed',
        'bridged',
        'macvlan',
    ];

    /**
     * Base verdict lookup table keyed by instanceType × normalized state × resource kind.
     *
     * @var array<string, array<string, array<string, ResourceUpdateVerdict>>>
     */
    private const array CAPABILITY_TABLE = [
        'container' => [
            'Running' => [
                ResourceKind::Cpu->value => ResourceUpdateVerdict::Online,
                ResourceKind::Ram->value => ResourceUpdateVerdict::Online,
                ResourceKind::Disk->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
            ],
            'Stopped' => [
                ResourceKind::Cpu->value => ResourceUpdateVerdict::Online,
                ResourceKind::Ram->value => ResourceUpdateVerdict::Online,
                ResourceKind::Disk->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
            ],
        ],
        'virtual-machine' => [
            'Running' => [
                ResourceKind::Cpu->value => ResourceUpdateVerdict::RequiresStop,
                ResourceKind::Ram->value => ResourceUpdateVerdict::RequiresStop,
                ResourceKind::Disk->value => ResourceUpdateVerdict::RequiresStop,
                ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
            ],
            'Stopped' => [
                ResourceKind::Cpu->value => ResourceUpdateVerdict::Online,
                ResourceKind::Ram->value => ResourceUpdateVerdict::Online,
                ResourceKind::Disk->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetDownload->value => ResourceUpdateVerdict::Online,
                ResourceKind::NetUpload->value => ResourceUpdateVerdict::Online,
            ],
        ],
    ];

    /**
     * Assess each resource dimension comparing observed hypervisor state against the target specification.
     *
     * @param  string|null  $nicType  Local NIC `nictype` (`null` means no local NIC device exists).
     */
    public function assess(
        InstanceResourceSpec $observed,
        InstanceResourceSpec $target,
        string $vmState,
        ?string $storageDriver = null,
        ?string $nicType = null,
    ): ResourceUpdateAssessment {
        $instanceType = $observed->instanceType === 'container' ? 'container' : 'virtual-machine';
        $normalizedState = $this->normalizeState($vmState);
        $normalizedStorage = $storageDriver !== null ? strtolower(trim($storageDriver)) : '';
        $normalizedNic = $nicType !== null ? strtolower(trim($nicType)) : null;

        /** @var array<string, ResourceUpdateVerdict> $verdicts */
        $verdicts = [];
        /** @var array<string, string> $reasons */
        $reasons = [];

        foreach (ResourceKind::cases() as $kind) {
            if (! $this->hasChanged($kind, $observed, $target)) {
                $verdicts[$kind->value] = ResourceUpdateVerdict::Unchanged;

                continue;
            }

            if ($kind === ResourceKind::Disk) {
                if ($target->diskGb < $observed->diskGb) {
                    $verdicts[$kind->value] = ResourceUpdateVerdict::Unsupported;
                    $reasons[$kind->value] = 'DISK_SHRINK';

                    continue;
                }

                if ($instanceType === 'container' && ! in_array($normalizedStorage, self::QUOTA_STORAGE_DRIVERS, true)) {
                    $verdicts[$kind->value] = ResourceUpdateVerdict::Unsupported;
                    $reasons[$kind->value] = 'STORAGE_QUOTA_UNSUPPORTED';

                    continue;
                }
            }

            if ($kind === ResourceKind::NetDownload || $kind === ResourceKind::NetUpload) {
                if ($normalizedNic === null) {
                    $verdicts[$kind->value] = ResourceUpdateVerdict::Unsupported;
                    $reasons[$kind->value] = 'NETWORK_LIMIT_NO_NIC';

                    continue;
                }

                if (! in_array($normalizedNic, self::CONFIRMED_NIC_TYPES, true)) {
                    $verdicts[$kind->value] = ResourceUpdateVerdict::Unsupported;
                    $reasons[$kind->value] = 'NIC_TYPE_UNSUPPORTED';

                    continue;
                }
            }

            $verdict = self::CAPABILITY_TABLE[$instanceType][$normalizedState][$kind->value]
                ?? ResourceUpdateVerdict::RequiresStop;

            $verdicts[$kind->value] = $verdict;
        }

        return new ResourceUpdateAssessment(
            verdicts: $verdicts,
            reasons: $reasons,
        );
    }

    private function normalizeState(string $vmState): string
    {
        return match (strtolower(trim($vmState))) {
            'stopped' => 'Stopped',
            'running' => 'Running',
            default => 'Other',
        };
    }

    private function hasChanged(ResourceKind $kind, InstanceResourceSpec $observed, InstanceResourceSpec $target): bool
    {
        return match ($kind) {
            ResourceKind::Cpu => $observed->cpuCores !== $target->cpuCores,
            ResourceKind::Ram => $observed->ramMb !== $target->ramMb,
            ResourceKind::Disk => $observed->diskGb !== $target->diskGb,
            ResourceKind::NetDownload => $observed->network?->downloadMbps !== $target->network?->downloadMbps,
            ResourceKind::NetUpload => $observed->network?->uploadMbps !== $target->network?->uploadMbps,
        };
    }
}
