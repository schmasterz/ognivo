<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\User\CreateUserDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating tenant user creation payload.
 */
final class CreateUserRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::in(['admin', 'customer'])],
            'status' => ['nullable', 'string', Rule::in(['active', 'suspended', 'inactive', 'pending'])],
            'quota_group_id' => ['nullable', 'integer', 'exists:quota_groups,id'],
            'quota' => ['nullable', 'array'],
            'quota.max_vms' => ['nullable', 'integer', 'min:-1'],
            'quota.max_cpu_cores' => ['nullable', 'integer', 'min:-1'],
            'quota.max_ram_mb' => ['nullable', 'integer', 'min:-1'],
            'quota.max_storage_gb' => ['nullable', 'integer', 'min:-1'],
            'quota.max_ips' => ['nullable', 'integer', 'min:-1'],
            'quota.max_snapshots' => ['nullable', 'integer', 'min:-1'],
        ];
    }

    public function toDTO(): CreateUserDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateUserDTO::fromArray($validated);
    }
}
