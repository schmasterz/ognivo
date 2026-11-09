<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Quota\CreateQuotaGroupDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating quota group creation payload.
 */
final class CreateQuotaGroupRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:quota_groups,name'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_default' => ['nullable', 'boolean'],
            'max_vms' => ['required', 'integer', 'min:-1'],
            'max_cpu_cores' => ['required', 'integer', 'min:-1'],
            'max_ram_mb' => ['required', 'integer', 'min:-1'],
            'max_storage_gb' => ['required', 'integer', 'min:-1'],
            'max_ips' => ['required', 'integer', 'min:-1'],
            'max_snapshots' => ['nullable', 'integer', 'min:-1'],
        ];
    }

    public function toDTO(): CreateQuotaGroupDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateQuotaGroupDTO::fromArray($validated);
    }
}
