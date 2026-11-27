<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * Public catalog API Resource exposing published VPS plan attributes without internal or audit fields.
 *
 * @property-read VpsPlan $resource
 *
 * @mixin VpsPlan
 */
final class VpsPlanCatalogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        /** @var VpsPlanVersion|null $currentVersion */
        $currentVersion = $plan->relationLoaded('currentVersion')
            ? $plan->currentVersion
            : $plan->currentVersion()->first();

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'sort_order' => $plan->sort_order,
            'current_version' => $currentVersion !== null ? [
                'id' => $currentVersion->id,
                'version' => $currentVersion->version,
                'cpu_cores' => $currentVersion->cpu_cores,
                'ram_mb' => $currentVersion->ram_mb,
                'disk_gb' => $currentVersion->disk_gb,
                'instance_type' => $currentVersion->instance_type,
                'net_download_mbps' => $currentVersion->net_download_mbps,
                'net_upload_mbps' => $currentVersion->net_upload_mbps,
            ] : null,
        ];
    }
}
