<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\DTO\Vm\CreateVmDTO;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating virtual machine provisioning payload.
 */
final class CreateVmRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        $hostId = $this->input('host_id');

        $nameRules = [
            'required',
            'string',
            'min:3',
            'max:63',
            'regex:/^[a-z0-9][a-z0-9\-]+[a-z0-9]$/',
        ];

        if ($hostId !== null) {
            $nameRules[] = Rule::unique('virtual_machines')->where(
                fn (Builder $query): Builder => $query
                    ->where('user_id', $user->id)
                    ->where('host_id', $hostId)
                    ->where('status', '!=', 'deleted')
            );
        }

        return [
            'name' => $nameRules,
            'instance_type' => ['nullable', 'string', Rule::in(['virtual-machine', 'container'])],
            'cpu_cores' => ['required', 'integer', 'min:1', 'max:64'],
            'ram_mb' => ['required', 'integer', 'min:512', 'max:131072'],
            'disk_gb' => ['required', 'integer', 'min:5', 'max:2000'],
            'os_image' => [
                'required',
                'string',
                'max:255',
                Rule::exists('images', 'name')->where('enabled', true),
            ],
            'host_id' => ['nullable', 'integer', 'exists:hosts,id'],
            'cloud_init' => ['nullable', 'array'],
            'cloud_init.user-data' => ['nullable', 'string'],
        ];
    }

    public function toDTO(): CreateVmDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateVmDTO::fromArray($validated);
    }
}
