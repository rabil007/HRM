<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BulkEmailDocumentsRequest extends FormRequest
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
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct'],
            'recipient' => ['required', 'email', 'max:255'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $raw = trim((string) $this->input('cc'));

            if ($raw === '') {
                return;
            }

            foreach ($this->parseCcEmails($raw) as $email) {
                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $validator->errors()->add('cc', 'One or more CC email addresses are invalid.');

                    return;
                }
            }
        });
    }

    /**
     * @return list<string>
     */
    public function ccRecipients(): array
    {
        return $this->parseCcEmails(trim((string) $this->validated('cc')));
    }

    /**
     * @return list<string>
     */
    private function parseCcEmails(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        return collect(preg_split('/\s*,\s*/', $raw) ?: [])
            ->filter(fn (string $email) => $email !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_ids.min' => 'Select at least one document to email.',
            'recipient.required' => 'Recipient email is required.',
            'recipient.email' => 'Enter a valid recipient email address.',
            'subject.required' => 'Email subject is required.',
        ];
    }
}
