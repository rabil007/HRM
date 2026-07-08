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
        ]);
    }
}
