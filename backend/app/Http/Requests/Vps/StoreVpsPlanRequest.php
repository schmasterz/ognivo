<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating creation of a new VPS catalog plan and its initial draft version.
 */
final class StoreVpsPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('vps.plans.manage') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'nullable',
                'string',
                'max:140',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('vps_plans', 'slug'),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ], UpsertVpsPlanDraftVersionRequest::profileRules());
    }
}
