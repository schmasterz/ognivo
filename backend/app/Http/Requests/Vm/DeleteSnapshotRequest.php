<?php

declare(strict_types=1);

namespace App\Http\Requests\Vm;

use App\Models\User;
use App\Models\VirtualMachine;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request authorizing virtual machine snapshot deletion.
 */
final class DeleteSnapshotRequest extends FormRequest
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
        return [];
    }
}
