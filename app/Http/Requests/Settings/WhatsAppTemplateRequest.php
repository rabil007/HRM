<?php

namespace App\Http\Requests\Settings;

use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class WhatsAppTemplateRequest extends FormRequest
{
    abstract protected function permission(): string;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->permission());
    }

    /** @return array<string, mixed> */
    protected function templateRules(?int $ignoreId = null): array
    {
        return [
            'slug' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('whatsapp_templates', 'slug')
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'label' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(WhatsAppTemplateCategory::class)],
            'meta_name' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+$/'],
            'meta_language' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'header_type' => ['required', Rule::enum(WhatsAppTemplateHeaderType::class)],
            'body_preview' => ['required', 'string', 'max:1024'],
            'is_default' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers, and underscores only.',
            'meta_name.regex' => 'Meta template name must use lowercase letters, numbers, and underscores only.',
            'meta_language.regex' => 'Use a Meta locale code such as en or en_US.',
        ];
    }
}
