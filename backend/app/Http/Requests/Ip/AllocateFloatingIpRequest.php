<?php

declare(strict_types=1);

namespace App\Http\Requests\Ip;

use App\DTO\Ip\AllocateFloatingIpDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating floating IP allocation parameters.
 */
final class AllocateFloatingIpRequest extends FormRequest
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
            'pool_id' => ['nullable', 'integer', 'exists:ip_pools,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toDTO(): AllocateFloatingIpDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return AllocateFloatingIpDTO::fromArray($validated);
    }
}
