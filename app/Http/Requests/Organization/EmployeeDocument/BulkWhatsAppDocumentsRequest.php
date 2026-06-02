<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class BulkWhatsAppDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct'],
            'whatsapp_number' => ['required', 'string', 'regex:/^\+?[1-9]\d{6,14}$/'],
            'send_template_first' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document_ids.min' => 'Select at least one document to send.',
            'whatsapp_number.required' => 'WhatsApp number is required.',
            'whatsapp_number.regex' => 'Enter a valid WhatsApp number with country code (e.g. +971501234567).',
        ];
    }
}
