<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Quota\DefaultQuotaDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating updates to the default system quota template.
 */
final class UpdateDefaultQuotaRequest extends FormRequest
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
            'max_vms' => ['required', 'integer', 'min:-1'],
            'max_cpu_cores' => ['required', 'integer', 'min:-1'],
            'max_ram_mb' => ['required', 'integer', 'min:-1'],
            'max_storage_gb' => ['required', 'integer', 'min:-1'],
            'max_ips' => ['required', 'integer', 'min:-1'],
            'max_snapshots' => ['nullable', 'integer', 'min:-1'],
        ];
    }

    public function toDTO(): DefaultQuotaDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return DefaultQuotaDTO::fromArray($validated);
    }
}
