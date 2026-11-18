<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateImageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255', 'unique:images,name'],
            'alias' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:500'],
            'protocol' => ['nullable', 'string', 'max:50'],
            'os_type' => ['nullable', 'string', Rule::in(['linux', 'windows', 'bsd', 'other'])],
            'enabled' => ['nullable', 'boolean'],
            'host_ids' => ['nullable', 'array'],
            'host_ids.*' => ['integer', 'exists:hosts,id'],
        ];
    }
}
