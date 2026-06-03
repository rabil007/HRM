<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use App\Http\Requests\Organization\EmployeeDocument\Concerns\AppliesEmployeeDocumentTemplateRules;
use Illuminate\Foundation\Http\FormRequest;

class BulkStoreEmployeeDocumentRequest extends FormRequest
{
    use AppliesEmployeeDocumentTemplateRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->applyEmployeeDocumentTemplateRules([
            'documents' => ['required', 'array', 'min:1', 'max:20'],
            'documents.*.document_type_id' => $this->requiredDocumentTypeIdRules(),
            'documents.*.title' => ['nullable', 'string', 'max:200'],
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'documents.*.issue_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date'],
            'documents.*.document_number' => ['nullable', 'string', 'max:120'],
            'documents.*.notes' => ['nullable', 'string', 'max:1000'],
        ], wildcard: true);
    }
}
