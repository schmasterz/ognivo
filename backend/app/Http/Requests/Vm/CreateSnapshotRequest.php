<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\DTO\Vm\CreateSnapshotDTO;
use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Form Request validating virtual machine snapshot creation payload.
 */
final class CreateSnapshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        $vm = $this->route('vm') ?? $this->route('virtualMachine');
        /** @var User|null $user */
        $user = $this->user();

        if ($vm instanceof VirtualMachine) {
            return $user !== null && $user->can('executeAction', $vm);
        }

        return $user !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $vm = $this->route('vm') ?? $this->route('virtualMachine');
        $vmId = $vm instanceof VirtualMachine ? $vm->id : (is_numeric($vm) ? (int) $vm : 0);

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:63',
                'regex:/^[a-z0-9](?:[a-z0-9_-]{0,61}[a-z0-9])?$/',
                Rule::unique('vm_snapshots', 'name')->where(
                    fn (Builder $query): Builder => $query->where('virtual_machine_id', $vmId)
                ),
            ],
            'stateful' => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($this->boolean('stateful')) {
                $instance = $this->route('vm') ?? $this->route('virtualMachine');
                if (! $instance instanceof VirtualMachine && (is_numeric($instance) || is_string($instance))) {
                    $instance = VirtualMachine::query()->find($instance);
                }

                if ($instance instanceof VirtualMachine && $instance->instance_type === 'container') {
                    $v->errors()->add(
                        'stateful',
                        'In-memory (stateful) snapshots are only supported for virtual machines, not containers.'
                    );
                }
            }
        });
    }

    public function toDTO(): CreateSnapshotDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateSnapshotDTO::fromArray($validated);
    }
}
