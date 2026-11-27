<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use App\Models\VpsPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating updates to VPS plan metadata fields.
 */
final class UpdateVpsPlanRequest extends FormRequest
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
        $plan = $this->route('plan');
        $planId = $plan instanceof VpsPlan ? $plan->id : (is_numeric($plan) ? (int) $plan : null);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:140',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('vps_plans', 'slug')->ignore($planId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
