<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\VpsPlan;
use App\Models\VpsPlanChange;
use App\Models\VpsPlanVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * API Resource transforming a VPS plan-change operation without exposing internal hypervisor details.
 *
 * @property-read VpsPlanChange $resource
 *
 * @mixin VpsPlanChange
 */
final class VpsPlanChangeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        $change = $this->resource;

        $fromVersion = null;
        if ($change->from_version_id !== null) {
            /** @var VpsPlanVersion|null $fromVersion */
            $fromVersion = $change->relationLoaded('fromVersion')
                ? $change->fromVersion
                : $change->fromVersion()->with('vpsPlan')->first();
        }

        /** @var VpsPlanVersion|null $toVersion */
        $toVersion = $change->relationLoaded('toVersion')
            ? $change->toVersion
            : $change->toVersion()->with('vpsPlan')->first();

        return [
            'public_id' => $change->public_id,
            'virtual_machine_id' => $change->virtual_machine_id,
            'status' => $change->status->getValue(),
            'stage' => $change->stage,
            'from_version_id' => $change->from_version_id,
            'from' => $this->formatVersionSummary($fromVersion),
            'to_version_id' => $change->to_version_id,
            'to' => $this->formatVersionSummary($toVersion),
            'old_resources' => $change->old_resources,
            'new_resources' => $change->new_resources,
            'error_code' => $change->error_code,
            'error_message' => self::sanitizeErrorMessage($change->error_message),
            'requested_at' => $change->requested_at?->toISOString(),
            'started_at' => $change->started_at?->toISOString(),
            'completed_at' => $change->completed_at?->toISOString(),
            'failed_at' => $change->failed_at?->toISOString(),
            'created_at' => $change->created_at?->toISOString(),
        ];
    }

    /**
     * Format plan and version metadata for a plan-change endpoint payload.
     *
     * @return array{
     *     vps_plan_id: int,
     *     vps_plan_version_id: int,
     *     plan_name: string|null,
     *     plan_slug: string|null,
     *     version: int|null,
     * }|null
     */
    private function formatVersionSummary(?VpsPlanVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        /** @var VpsPlan|null $plan */
        $plan = $version->relationLoaded('vpsPlan')
            ? $version->vpsPlan
            : $version->vpsPlan()->first();

        return [
            'vps_plan_id' => $version->vps_plan_id,
            'vps_plan_version_id' => $version->id,
            'plan_name' => $plan?->name,
            'plan_slug' => $plan?->slug,
            'version' => $version->version,
        ];
    }

    /**
     * Redact internal hypervisor URLs, PEM certificates, endpoints, and tokens from error messages.
     */
    private static function sanitizeErrorMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $sanitized = preg_replace('/(?:https?|wss?|unix):\/\/[^\s"\'<>]+/i', '[redacted]', $message) ?? $message;
        $sanitized = preg_replace('/-----BEGIN [^-]+-----[\s\S]*?-----END [^-]+-----/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\bBearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\bsrv_[A-Za-z0-9._\-]+/', '[redacted]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}:\d+\b/', '[redacted]', $sanitized) ?? $sanitized;

        return $sanitized;
    }
}
