<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTO\Auth\UpdateProfileDTO;
use App\DTO\Cast;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request validating user profile and password change updates.
 */
final class UpdateProfileRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'current_password' => [
                'nullable',
                'string',
                Rule::requiredIf(fn (): bool => $this->filled('password') ||
                    ($this->has('email') && strcasecmp(Cast::string($this->input('email')), $user->email) !== 0)
                ),
            ],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function toDTO(): UpdateProfileDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateProfileDTO::fromArray($validated);
    }
}
