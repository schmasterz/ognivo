<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\System\UpdateSettingsDTO;
use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Form Request validating system platform settings payload.
 */
final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_enabled' => ['sometimes', 'boolean'],
            'allow_virtual_machines' => ['sometimes', 'boolean'],
            'allow_containers' => ['sometimes', 'boolean'],
            'frontend_url' => ['sometimes', 'nullable', 'string', 'url', 'max:255'],
            'smtp' => ['sometimes', 'nullable', 'array'],
            'smtp.enabled' => ['sometimes', 'boolean'],
            'smtp.host' => ['nullable', 'string', 'max:255'],
            'smtp.port' => ['nullable', 'integer', 'between:1,65535'],
            'smtp.encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'smtp.username' => ['nullable', 'string', 'max:255'],
            'smtp.password' => ['nullable', 'string', 'max:255'],
            'smtp.from_address' => ['nullable', 'email', 'max:255'],
            'smtp.from_name' => ['nullable', 'string', 'max:255'],
            'metrics_enabled' => ['sometimes', 'boolean'],
            'metrics_interval_seconds' => ['sometimes', 'integer', 'min:30', 'max:300'],
            'metrics_retention_raw_hours' => ['sometimes', 'integer', 'min:6', 'max:168'],
            'metrics_retention_5m_days' => ['sometimes', 'integer', 'min:8', 'max:365'],
            'metrics_retention_1h_days' => ['sometimes', 'integer', 'min:35', 'max:730'],
        ];
    }

    /**
     * Configure validator instance to ensure at least one virtualization mode is enabled.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $allowVm = $this->has('allow_virtual_machines')
                ? $this->boolean('allow_virtual_machines')
                : SystemSetting::isVmEnabled();

            $allowContainer = $this->has('allow_containers')
                ? $this->boolean('allow_containers')
                : SystemSetting::isContainerEnabled();

            if (! $allowVm && ! $allowContainer) {
                /** @var string $msg */
                $msg = __('messages.admin.at_least_one_mode_required');
                $v->errors()->add('virtualization_modes', $msg);
            }
        });
    }

    public function toDTO(): UpdateSettingsDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateSettingsDTO::fromArray($validated);
    }
}
