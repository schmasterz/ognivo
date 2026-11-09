<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\UserQuota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming UserQuota entity.
 *
 * @property-read UserQuota $resource
 *
 * @mixin UserQuota
 */
final class UserQuotaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $quota = $this->resource;

        return [
            'id' => $quota->id,
            'user_id' => $quota->user_id,
            'quota_group_id' => $quota->quota_group_id,
            'max_vms' => $quota->max_vms,
            'max_cpu_cores' => $quota->max_cpu_cores,
            'max_ram_mb' => $quota->max_ram_mb,
            'max_storage_gb' => $quota->max_storage_gb,
            'max_ips' => $quota->max_ips,
            'max_snapshots' => $quota->max_snapshots,
            'created_at' => $quota->created_at?->toISOString(),
            'updated_at' => $quota->updated_at?->toISOString(),
            'quota_group' => $this->whenLoaded('quotaGroup', fn (): ?QuotaGroupResource => $quota->quotaGroup !== null ? new QuotaGroupResource($quota->quotaGroup) : null),
        ];
    }
}
