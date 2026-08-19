<?php

namespace App\Http\Requests\Settings;

use App\Support\Platform\PlatformAuthorization;
use Illuminate\Foundation\Http\FormRequest;

class SendWhatsAppTestTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlatformAuthorization::canManage($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid WhatsApp number with country code (e.g. +971501234567).',
        ];
    }
}
