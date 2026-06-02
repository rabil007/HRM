<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use App\Enums\WhatsAppTemplateCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'template_slug' => [
                'required',
                'string',
                Rule::exists('whatsapp_templates', 'slug')
                    ->where('enabled', true)
                    ->where('category', WhatsAppTemplateCategory::Document->value)
                    ->where('header_type', 'document'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'whatsapp_number.required' => 'WhatsApp number is required.',
            'whatsapp_number.regex' => 'Enter a valid WhatsApp number with country code (e.g. +971501234567).',
            'template_slug.required' => 'Select a WhatsApp template.',
            'template_slug.exists' => 'Selected WhatsApp template is not available.',
        ];
    }
}
