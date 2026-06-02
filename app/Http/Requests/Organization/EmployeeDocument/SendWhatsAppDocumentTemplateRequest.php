<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class SendWhatsAppDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'whatsapp_number' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'whatsapp_number.required' => 'WhatsApp number is required.',
            'whatsapp_number.regex' => 'Enter a valid WhatsApp number with country code (e.g. +971501234567).',
        ];
    }
}
