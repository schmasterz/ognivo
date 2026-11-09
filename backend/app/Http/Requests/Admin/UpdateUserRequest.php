<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\User\UpdateUserDTO;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating tenant user updates.
 */
final class UpdateUserRequest extends FormRequest
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
        $user = $this->route('user');
        $userId = $user instanceof User ? $user->id : (is_numeric($user) ? (int) $user : null);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'password' => ['nullable', 'string', 'min:8'],
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

    public function toDTO(): UpdateUserDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateUserDTO::fromArray($validated);
    }
}
