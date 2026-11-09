<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\User\AssignQuotaGroupDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating quota group assignment to a user.
 */
final class AssignQuotaGroupRequest extends FormRequest
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
            'quota_group_id' => ['required', 'integer', 'exists:quota_groups,id'],
        ];
    }

    public function toDTO(): AssignQuotaGroupDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return AssignQuotaGroupDTO::fromArray($validated);
    }
}
