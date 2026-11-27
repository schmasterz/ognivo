<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\DTO\Vm\CreateVmDTO;
use App\Models\User;
use App\Support\Vps\ResourceBounds;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Form Request validating virtual machine provisioning payload.
 */
final class CreateVmRequest extends FormRequest
{
    /**
     * Custom resource fields forbidden for non-administrator provisioning requests.
     *
     * @var list<string>
     */
    private const array OVERRIDE_FIELDS = [
        'cpu_cores',
        'ram_mb',
        'disk_gb',
        'instance_type',
        'net_download_mbps',
        'net_upload_mbps',
    ];

    public function authorize(): bool
    {
        if ($this->attributes->get('service_token') !== null) {
            return false;
        }

        if (str_starts_with($this->bearerToken() ?? '', 'srv_')) {
            return false;
        }

        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $isAdmin = $user->hasRole('admin');

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

        $rules = [
            'name' => $nameRules,
            'vps_plan_id' => $isAdmin
                ? ['nullable', 'integer', 'exists:vps_plans,id']
                : ['required', 'integer', 'exists:vps_plans,id'],
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

        if ($isAdmin) {
            $rules['instance_type'] = ['nullable', 'string', Rule::in(ResourceBounds::INSTANCE_TYPES)];
            $rules['cpu_cores'] = [
                'required_without:vps_plan_id',
                'nullable',
                'integer',
                'min:'.ResourceBounds::CPU_MIN,
                'max:'.ResourceBounds::CPU_MAX,
            ];
            $rules['ram_mb'] = [
                'required_without:vps_plan_id',
                'nullable',
                'integer',
                'min:'.ResourceBounds::RAM_MB_MIN,
                'max:'.ResourceBounds::RAM_MB_MAX,
            ];
            $rules['disk_gb'] = [
                'required_without:vps_plan_id',
                'nullable',
                'integer',
                'min:'.ResourceBounds::DISK_GB_MIN,
                'max:'.ResourceBounds::DISK_GB_MAX,
            ];
        }

        return $rules;
    }

    /**
     * Reject custom resource override fields submitted by non-administrator users.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (): void {
            /** @var User|null $user */
            $user = $this->user();
            if ($user === null || $user->hasRole('admin')) {
                return;
            }

            $overrideFields = [];
            foreach (self::OVERRIDE_FIELDS as $field) {
                if ($this->exists($field)) {
                    $overrideFields[] = $field;
                }
            }

            if ($overrideFields === []) {
                return;
            }

            $errors = [];
            foreach ($overrideFields as $field) {
                $errors[$field] = ['Custom resource fields are not allowed. Please select a VPS plan.'];
            }

            $exception = ValidationException::withMessages($errors);
            $exception->response = response()->json([
                'message' => 'Custom resource overrides are not allowed. Please select a VPS plan.',
                'code' => 'RESOURCE_OVERRIDE_NOT_ALLOWED',
                'errors' => $errors,
            ], 422);

            throw $exception;
        });
    }

    public function toDTO(): CreateVmDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateVmDTO::fromArray($validated);
    }
}
