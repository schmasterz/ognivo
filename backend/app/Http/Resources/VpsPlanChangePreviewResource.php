<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VirtualMachine;
use App\Models\VpsPlan;
use App\Models\VpsPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming a preflight VPS plan-change preview evaluation.
 *
 * @property-read VirtualMachine $resource
 *
 * @mixin VirtualMachine
 */
final class VpsPlanChangePreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $vm = $this->resource;

        /** @var mixed $rawTargetVersion */
        $rawTargetVersion = $vm->getAttribute('plan_change_target_version');
        $targetVersion = $rawTargetVersion instanceof VpsPlanVersion ? $rawTargetVersion : null;

        /** @var mixed $rawValidation */
        $rawValidation = $vm->getAttribute('plan_change_validation');
        /** @var array{
         *     can_submit?: bool,
         *     requires_shutdown?: bool,
         *     changes?: array<string, string>,
         *     warnings?: list<string>,
         *     blocking_reasons?: list<array{code: string, message: string, kind?: string}>,
         * } $validation
         */
        $validation = is_array($rawValidation) ? $rawValidation : [];

        $fromVersion = null;
        $fromPlan = null;
        if ($vm->vps_plan_version_id !== null) {
            /** @var VpsPlanVersion|null $fromVersion */
            $fromVersion = $vm->relationLoaded('vpsPlanVersion')
                ? $vm->vpsPlanVersion
                : $vm->vpsPlanVersion()->with('vpsPlan')->first();

            if ($fromVersion !== null) {
                /** @var VpsPlan|null $fromPlan */
                $fromPlan = $fromVersion->relationLoaded('vpsPlan')
                    ? $fromVersion->vpsPlan
                    : $fromVersion->vpsPlan()->first();
            }
        }

        /** @var VpsPlan|null $targetPlan */
        $targetPlan = $targetVersion !== null
            ? ($targetVersion->relationLoaded('vpsPlan')
                ? $targetVersion->vpsPlan
                : $targetVersion->vpsPlan()->first())
            : null;

        return [
            'can_submit' => (bool) ($validation['can_submit'] ?? false),
            'requires_shutdown' => (bool) ($validation['requires_shutdown'] ?? false),
            'from' => [
                'vps_plan_id' => $fromPlan?->id,
                'vps_plan_version_id' => $fromVersion?->id,
                'plan_name' => $fromPlan?->name,
                'plan_slug' => $fromPlan?->slug,
                'version' => $fromVersion?->version,
                'cpu_cores' => (int) $vm->cpu_cores,
                'ram_mb' => (int) $vm->ram_mb,
                'disk_gb' => (int) $vm->disk_gb,
                'instance_type' => (string) $vm->instance_type,
                'net_download_mbps' => $vm->net_download_mbps,
                'net_upload_mbps' => $vm->net_upload_mbps,
            ],
            'to' => $targetVersion !== null ? [
                'vps_plan_id' => $targetVersion->vps_plan_id,
                'vps_plan_version_id' => $targetVersion->id,
                'plan_name' => $targetPlan?->name,
                'plan_slug' => $targetPlan?->slug,
                'version' => $targetVersion->version,
                'cpu_cores' => (int) $targetVersion->cpu_cores,
                'ram_mb' => (int) $targetVersion->ram_mb,
                'disk_gb' => (int) $targetVersion->disk_gb,
                'instance_type' => (string) $targetVersion->instance_type,
                'net_download_mbps' => $targetVersion->net_download_mbps,
                'net_upload_mbps' => $targetVersion->net_upload_mbps,
            ] : null,
            'changes' => $validation['changes'] ?? [],
            'warnings' => $validation['warnings'] ?? [],
            'blocking_reasons' => $validation['blocking_reasons'] ?? [],
        ];
    }
}
