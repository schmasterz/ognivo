<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating administrative resolution of a VPS plan change requiring reconciliation.
 */
final class ResolvePlanChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vps.plan_change.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(['complete', 'fail', 'retry'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
