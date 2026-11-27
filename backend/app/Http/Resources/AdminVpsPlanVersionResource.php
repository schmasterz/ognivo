<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTO\Cast;
use App\Models\VpsPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * Administrative API Resource representing a VPS plan version snapshot.
 *
 * @property-read VpsPlanVersion $resource
 *
 * @mixin VpsPlanVersion
 */
final class AdminVpsPlanVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $version = $this->resource;

        return [
            'id' => $version->id,
            'vps_plan_id' => $version->vps_plan_id,
            'version' => $version->version,
            'status' => $version->status->getValue(),
            'cpu_cores' => $version->cpu_cores,
            'ram_mb' => $version->ram_mb,
            'disk_gb' => $version->disk_gb,
            'instance_type' => $version->instance_type,
            'net_download_mbps' => $version->net_download_mbps,
            'net_upload_mbps' => $version->net_upload_mbps,
            'change_summary' => $version->change_summary,
            'active_vms_count' => $this->when(
                array_key_exists('active_vms_count', $version->getAttributes()),
                fn (): int => Cast::int($version->getAttribute('active_vms_count'))
            ),
            'created_by' => $version->created_by,
            'published_by' => $version->published_by,
            'created_at' => $version->created_at?->toISOString(),
            'published_at' => $version->published_at?->toISOString(),
        ];
    }
}
