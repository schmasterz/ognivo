<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource representing a candidate target VPS plan and its preflight eligibility for a VM.
 *
 * @property-read VpsPlan $resource
 *
 * @mixin VpsPlan
 */
final class VpsPlanChangeTargetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        /** @var VpsPlanVersion|null $version */
        $version = $plan->relationLoaded('currentVersion')
            ? $plan->currentVersion
            : $plan->currentVersion()->first();

        /** @var mixed $rawValidation */
        $rawValidation = $plan->getAttribute('plan_change_validation');
        /** @var array{
         *     can_submit?: bool,
         *     requires_shutdown?: bool,
         *     changes?: array<string, string>,
         *     warnings?: list<string>,
         *     blocking_reasons?: list<array{code: string, message: string, kind?: string}>,
         * } $validation
         */
        $validation = is_array($rawValidation) ? $rawValidation : [];

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'description' => $plan->description,
            'sort_order' => $plan->sort_order,
            'current_version' => $version !== null ? [
                'id' => $version->id,
                'version' => $version->version,
                'cpu_cores' => $version->cpu_cores,
                'ram_mb' => $version->ram_mb,
                'disk_gb' => $version->disk_gb,
                'instance_type' => $version->instance_type,
                'net_download_mbps' => $version->net_download_mbps,
                'net_upload_mbps' => $version->net_upload_mbps,
            ] : null,
            'selectable' => (bool) ($validation['can_submit'] ?? false),
            'requires_shutdown' => (bool) ($validation['requires_shutdown'] ?? false),
            'changes' => $validation['changes'] ?? [],
            'warnings' => $validation['warnings'] ?? [],
            'blocking_reasons' => $validation['blocking_reasons'] ?? [],
        ];
    }
}
