<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ValidateImageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'source' => ['required', 'string', 'max:500'],
            'protocol' => ['nullable', 'string', 'max:50'],
            'os_type' => ['nullable', 'string'],
        ];
    }
}
