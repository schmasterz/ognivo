<?php

declare(strict_types=1);

namespace App\Events\Vps;

use App\Events\AbstractOgnivoEvent;
use Override;

/**
 * Domain event emitted when a draft VPS plan version is created or updated.
 *
 * @experimental This event is in Developer Preview and may evolve in minor 1.x releases based on feedback from real reference implementations. It will be frozen under full SemVer once proven with core-integrated modules.
 */
final class VpsPlanVersionCreated extends AbstractOgnivoEvent
{
    /**
     * @param  array{type: string, id: string|null, label: string|null}|null  $actor
     */
    public function __construct(
        public readonly int $planId,
        public readonly int $versionId,
        public readonly int $cpuCores,
        public readonly int $ramMb,
        public readonly int $diskGb,
        public readonly string $instanceType,
        public readonly ?int $netDownloadMbps = null,
        public readonly ?int $netUploadMbps = null,
        public readonly ?string $changeSummary = null,
        ?array $actor = null,
    ) {
        parent::__construct(actor: $actor);
    }

    #[Override]
    public function getEventName(): string
    {
        return 'vps.plan.version.created';
    }

    /**
     * @return array{
     *     vps_plan_id: int,
     *     vps_plan_version_id: int,
     *     cpu_cores: int,
     *     ram_mb: int,
     *     disk_gb: int,
     *     instance_type: string,
     *     net_download_mbps: int|null,
     *     net_upload_mbps: int|null,
     *     change_summary: string|null,
     * }
     */
    #[Override]
    public function getPayload(): array
    {
        return [
            'vps_plan_id' => $this->planId,
            'vps_plan_version_id' => $this->versionId,
            'cpu_cores' => $this->cpuCores,
            'ram_mb' => $this->ramMb,
            'disk_gb' => $this->diskGb,
            'instance_type' => $this->instanceType,
            'net_download_mbps' => $this->netDownloadMbps,
            'net_upload_mbps' => $this->netUploadMbps,
            'change_summary' => $this->changeSummary,
        ];
    }
}
