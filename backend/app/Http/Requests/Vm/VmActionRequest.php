<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\DTO\Vm\VmActionDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating virtual machine lifecycle actions.
 */
final class VmActionRequest extends FormRequest
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
            'action' => ['required', 'string', Rule::in(['start', 'stop', 'restart', 'suspend', 'delete', 'reinstall'])],
            'force' => ['nullable', 'boolean'],
            'os_image' => ['nullable', 'string', 'max:255', Rule::exists('images', 'name')->where('enabled', true)],
            'cloud_init' => ['nullable', 'array'],
            'cloud_init.user-data' => ['nullable', 'string'],
        ];
    }

    public function toDTO(): VmActionDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return VmActionDTO::fromArray($validated);
    }
}
