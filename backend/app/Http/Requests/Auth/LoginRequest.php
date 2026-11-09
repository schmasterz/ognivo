<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\DTO\Auth\LoginDTO;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating user credentials.
 */
final class LoginRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toDTO(): LoginDTO
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return LoginDTO::fromArray($validated);
    }
}
