<?php

declare(strict_types=1);

namespace App\Http\Requests\Ip;

use App\Enums\IpAllocationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating query parameters for listing IP allocations.
 */
final class ListIpAllocationsRequest extends FormRequest
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
            'user_id' => ['nullable', 'integer', 'min:1'],
            'host_id' => ['nullable', 'integer', 'min:1'],
            'pool_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::enum(IpAllocationStatus::class)],
            'search' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
