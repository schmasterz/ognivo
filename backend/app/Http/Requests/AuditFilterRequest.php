<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\DTO\Audit\AuditFilterDTO;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating audit log query and filter parameters.
 */
final class AuditFilterRequest extends FormRequest
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
        return [
            'actor_id' => ['nullable', 'integer'],
            'tenant_user_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:50'],
            'action' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', 'string', Rule::in(['success', 'denied', 'failed'])],
            'subject_type' => ['nullable', 'string', 'max:50'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'request_id' => ['nullable', 'string', 'max:36'],
            'ip' => ['nullable', 'string', 'ip'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string'],
            'format' => ['nullable', 'string', Rule::in(['ndjson', 'csv'])],
        ];
    }

    public function toDTO(): AuditFilterDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return AuditFilterDTO::fromArray($validated);
    }
}
