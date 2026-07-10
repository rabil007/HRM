<?php

namespace App\Http\Requests\Organization\BulkDocuments;

class EmailBulkDocumentsRequest extends BulkDocumentActionRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulk_documents.email') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'email_template_id' => ['nullable', 'integer', 'exists:email_templates,id'],
            'email_intent' => ['nullable', 'string', 'in:initial,reminder'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email', 'max:255'],
        ]);
    }

    public function emailIntent(): string
    {
        $intent = $this->validated('email_intent');

        return is_string($intent) && $intent !== '' ? $intent : 'initial';
    }

    /**
     * @return list<string>
     */
    public function ccRecipients(): array
    {
        /** @var list<string>|null $cc */
        $cc = $this->validated('cc');

        if ($cc === null) {
            return [];
        }

        return array_values($cc);
    }
}
