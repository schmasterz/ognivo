<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use App\DTO\Vps\ResourceBounds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating creation or replacement of a VPS plan draft version profile.
 */
final class UpsertVpsPlanDraftVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vps.plans.manage') ?? false;
    }

    /**
     * Shared validation rules for VPS plan resource profiles.
     *
     * @return array<string, list<mixed>>
     */
    public static function profileRules(): array
    {
        return [
            'cpu_cores' => ['required', 'integer', 'min:'.ResourceBounds::CPU_MIN, 'max:'.ResourceBounds::CPU_MAX],
            'ram_mb' => ['required', 'integer', 'min:'.ResourceBounds::RAM_MB_MIN, 'max:'.ResourceBounds::RAM_MB_MAX],
            'disk_gb' => ['required', 'integer', 'min:'.ResourceBounds::DISK_GB_MIN, 'max:'.ResourceBounds::DISK_GB_MAX],
            'instance_type' => ['required', 'string', Rule::in(ResourceBounds::INSTANCE_TYPES)],
            'net_download_mbps' => ['nullable', 'integer', 'min:'.ResourceBounds::NET_MBPS_MIN, 'max:'.ResourceBounds::NET_MBPS_MAX],
            'net_upload_mbps' => ['nullable', 'integer', 'min:'.ResourceBounds::NET_MBPS_MIN, 'max:'.ResourceBounds::NET_MBPS_MAX],
            'change_summary' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return self::profileRules();
    }
}
