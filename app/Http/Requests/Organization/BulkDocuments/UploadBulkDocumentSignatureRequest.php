<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use Illuminate\Foundation\Http\FormRequest;

class UploadBulkDocumentSignatureRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }
}
