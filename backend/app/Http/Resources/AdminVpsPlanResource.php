<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VpsPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * Administrative API Resource representing a VPS catalog plan and its active/draft versions.
 *
 * @property-read VpsPlan $resource
 *
 * @mixin VpsPlan
 */
final class AdminVpsPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'status' => $plan->status->getValue(),
            'sort_order' => $plan->sort_order,
            'current_version_id' => $plan->current_version_id,
            'current_version' => $this->whenLoaded(
                'currentVersion',
                fn (): ?AdminVpsPlanVersionResource => $plan->currentVersion !== null
                    ? new AdminVpsPlanVersionResource($plan->currentVersion)
                    : null
            ),
            'draft_version' => $this->whenLoaded(
                'draftVersion',
                fn (): ?AdminVpsPlanVersionResource => $plan->draftVersion !== null
                    ? new AdminVpsPlanVersionResource($plan->draftVersion)
                    : null
            ),
            'created_by' => $plan->created_by,
            'updated_by' => $plan->updated_by,
            'created_at' => $plan->created_at?->toISOString(),
            'updated_at' => $plan->updated_at?->toISOString(),
        ];
    }
}
