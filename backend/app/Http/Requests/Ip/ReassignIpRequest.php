<?php

declare(strict_types=1);

namespace App\Http\Requests\Ip;

use App\DTO\Ip\ReassignIpDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating IP address reassignment / attachment to a VM.
 */
final class ReassignIpRequest extends FormRequest
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
            'virtual_machine_id' => ['nullable', 'integer', 'exists:virtual_machines,id'],
        ];
    }

    public function toDTO(): ReassignIpDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ReassignIpDTO::fromArray($validated);
    }
}
