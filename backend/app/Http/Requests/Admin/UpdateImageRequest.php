<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Image;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateImageRequest extends FormRequest
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
        /** @var Image|null $image */
        $image = $this->route('image');
        $imageId = $image?->id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('images', 'name')->ignore($imageId)],
            'alias' => ['sometimes', 'required', 'string', 'max:255'],
            'source' => ['sometimes', 'required', 'string', 'max:500'],
            'protocol' => ['nullable', 'string', 'max:50'],
            'os_type' => ['nullable', 'string', Rule::in(['linux', 'windows', 'bsd', 'other'])],
            'enabled' => ['nullable', 'boolean'],
            'host_ids' => ['nullable', 'array'],
            'host_ids.*' => ['integer', 'exists:hosts,id'],
        ];
    }
}
