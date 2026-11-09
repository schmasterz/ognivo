<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\QuotaGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming QuotaGroup entity.
 *
 * @property-read QuotaGroup $resource
 *
 * @mixin QuotaGroup
 */
final class QuotaGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $group = $this->resource;

        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'is_default' => (bool) $group->is_default,
            'max_vms' => $group->max_vms,
            'max_cpu_cores' => $group->max_cpu_cores,
            'max_ram_mb' => $group->max_ram_mb,
            'max_storage_gb' => $group->max_storage_gb,
            'max_ips' => $group->max_ips,
            'max_snapshots' => $group->max_snapshots,
            'users_count' => $this->whenCounted('users'),
            'created_at' => $group->created_at?->toISOString(),
            'updated_at' => $group->updated_at?->toISOString(),
        ];
    }
}
