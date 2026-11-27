<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Host\CreateHostDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating creation of a new hypervisor host node.
 */
final class CreateHostRequest extends FormRequest
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
        $allowedDrivers = $this->allowsFakeDriver() ? ['fake', 'incus', 'lxd'] : ['incus', 'lxd'];

        return array_merge([
            'name' => ['required', 'string', 'max:255', 'unique:hosts,name'],
            'region' => ['nullable', 'string', 'max:255'],
            'endpoint_url' => ['required_without:token', 'nullable', 'string', 'max:255'],
            'driver' => ['nullable', 'string', Rule::in($allowedDrivers)],
            'token' => ['nullable', 'string'],
            'expected_fingerprint' => ['nullable', 'string', 'max:128'],
            'is_active' => ['nullable', 'boolean'],
            'total_cpu_cores' => ['nullable', 'integer', 'min:1'],
            'total_ram_mb' => ['nullable', 'integer', 'min:1024'],
            'total_storage_gb' => ['nullable', 'integer', 'min:10'],
            'cpu_overcommit' => ['nullable', 'numeric', 'min:1', 'max:16'],
            'ram_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'disk_overcommit' => ['nullable', 'numeric', 'min:1', 'max:4'],
            'settings' => ['nullable', 'array'],
        ], ImageSelectionRules::rules());
    }

    public function toDTO(): CreateHostDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateHostDTO::fromArray($validated, $this->allowsFakeDriver() ? 'fake' : 'incus');
    }

    private function allowsFakeDriver(): bool
    {
        return in_array((string) app()->environment(), ['local', 'testing'], true)
            || (bool) config('app.allow_fake_driver');
    }
}
