<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\DTO\Host\UpdateHostDTO;
use App\Models\Host;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating host node updates.
 */
final class UpdateHostRequest extends FormRequest
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
        $host = $this->route('host');
        $hostId = $host instanceof Host ? $host->id : (is_numeric($host) ? (int) $host : null);

        $allowFake = app()->environment(['local', 'testing']) || (bool) config('app.allow_fake_driver');
        $allowedDrivers = $allowFake ? ['fake', 'incus', 'lxd'] : ['incus', 'lxd'];

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('hosts')->ignore($hostId)],
            'region' => ['nullable', 'string', 'max:255'],
            'endpoint_url' => ['required', 'string', 'max:255'],
            'driver' => ['nullable', 'string', Rule::in($allowedDrivers)],
            'token' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'total_cpu_cores' => ['nullable', 'integer', 'min:1'],
            'total_ram_mb' => ['nullable', 'integer', 'min:1024'],
            'total_storage_gb' => ['nullable', 'integer', 'min:10'],
            'settings' => ['nullable', 'array'],
        ];
    }

    public function toDTO(): UpdateHostDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateHostDTO::fromArray($validated);
    }
}
