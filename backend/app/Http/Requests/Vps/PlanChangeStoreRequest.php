<?php

declare(strict_types=1);

namespace App\Http\Requests\Vps;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Form Request validating VPS plan-change submission payloads.
 */
final class PlanChangeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlanChangePreviewRequest::authorizePlanChangeRequest($this);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var User|null $user */
        $user = $this->user();
        $isAdmin = $user !== null && $user->hasRole('admin');

        return PlanChangePreviewRequest::sharedRules($isAdmin);
    }

    public function withValidator(Validator $validator): void
    {
        PlanChangePreviewRequest::applySharedValidator($this, $validator);
    }
}
