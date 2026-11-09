<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Quota\UpdateQuotaGroupDTO;
use App\Models\QuotaGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating quota group updates.
 */
final class UpdateQuotaGroupRequest extends FormRequest
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
        $group = $this->route('quotaGroup');
        $groupId = $group instanceof QuotaGroup ? $group->id : (is_numeric($group) ? (int) $group : null);

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('quota_groups')->ignore($groupId)],
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

    public function toDTO(): UpdateQuotaGroupDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateQuotaGroupDTO::fromArray($validated);
    }
}
