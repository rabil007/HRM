<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use Illuminate\Foundation\Http\FormRequest;

class RejectBulkDocumentSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulk_documents.signatures.review') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
