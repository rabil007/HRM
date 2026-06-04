<?php

namespace App\Http\Requests\Settings;

use App\Enums\EmailTemplateCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class EmailTemplateRequest extends FormRequest
{
    abstract protected function permission(): string;

    protected function prepareForValidation(): void
    {
        $slug = (string) $this->input('slug');
        $expiryAlertSlug = (string) config('documents.expiry_alert_template_slug', 'document_expiry_alert');

        $this->merge([
            'to_preset' => $this->nullablePreset($this->input('to_preset')),
            'cc_preset' => $this->nullablePreset($this->input('cc_preset')),
            'dispatch_at' => $slug === $expiryAlertSlug
                ? $this->normalizeDispatchAt($this->input('dispatch_at'))
                : null,
        ]);
    }

    private function normalizeDispatchAt(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullablePreset(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

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
                Rule::unique('email_templates', 'slug')
                    ->ignore($ignoreId)
                    ->whereNull('deleted_at'),
            ],
            'label' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(EmailTemplateCategory::class)],
            'to_preset' => ['nullable', 'string', 'max:1000'],
            'cc_preset' => ['nullable', 'string', 'max:1000'],
            'dispatch_at' => ['nullable', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'subject' => ['required', 'string', 'max:255'],
            'body_html' => ['required', 'string', 'max:65535'],
            'is_default' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (['to_preset' => 'To preset', 'cc_preset' => 'CC preset'] as $field => $label) {
                $raw = trim((string) $this->input($field));

                if ($raw === '') {
                    continue;
                }

                foreach ($this->parseCommaSeparatedEmails($raw) as $email) {
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $validator->errors()->add($field, "One or more {$label} addresses are invalid.");

                        break;
                    }
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    private function parseCommaSeparatedEmails(string $raw): array
    {
        return collect(preg_split('/\s*,\s*/', $raw) ?: [])
            ->map(fn (string $email) => trim($email))
            ->filter(fn (string $email) => $email !== '')
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers, and underscores only.',
            'dispatch_at.regex' => 'Use 24-hour time in HH:MM format (e.g. 08:00).',
        ];
    }
}
