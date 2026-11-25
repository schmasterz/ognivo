<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request validating webhook endpoint creation payload.
 */
final class CreateWebhookEndpointRequest extends FormRequest
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
            'url' => ['required', 'url', 'max:500'],
            'subscribed_events' => ['required', 'array', 'min:1'],
            'subscribed_events.*' => ['required', 'string', 'max:100'],
        ];
    }
}
