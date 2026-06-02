<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class SendWhatsAppTestDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.integrations.whatsapp.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'file' => ['required', 'file', 'max:16384'],
            'caption' => ['nullable', 'string', 'max:1024'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid WhatsApp number with country code (e.g. +971501234567).',
            'file.max' => 'The test file may not be larger than 16 MB.',
        ];
    }
}
