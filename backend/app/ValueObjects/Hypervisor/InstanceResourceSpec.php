<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

/**
 * Target or observed compute, storage, and network resource specification for an instance.
 *
 * @experimental This DTO is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class InstanceResourceSpec
{
    /**
     * @param  int  $cpuCores  Allocated vCPU count.
     * @param  int  $ramMb  Allocated memory in MiB.
     * @param  int  $diskGb  Allocated root disk size in GiB.
     * @param  string  $instanceType  Instance type ('container' or 'virtual-machine').
     * @param  NetworkLimitsSpec|null  $network  Optional NIC bandwidth limits.
     */
    public function __construct(
        public int $cpuCores,
        public int $ramMb,
        public int $diskGb,
        public string $instanceType,
        public ?NetworkLimitsSpec $network = null,
    ) {}
}
