<?php

declare(strict_types=1);

namespace App\ValueObjects\Hypervisor;

/**
 * Network bandwidth limits (in Mbps) for an instance NIC device.
 *
 * @experimental This DTO is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final readonly class NetworkLimitsSpec
{
    public function __construct(
        public ?int $downloadMbps = null,
        public ?int $uploadMbps = null,
    ) {}
}
