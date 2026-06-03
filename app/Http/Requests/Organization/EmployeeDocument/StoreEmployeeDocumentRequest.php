<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use App\Http\Requests\Organization\EmployeeDocument\Concerns\AppliesEmployeeDocumentTemplateRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeDocumentRequest extends FormRequest
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
        $rules = $this->applyEmployeeDocumentTemplateRules([
            'document_type_id' => $this->requiredDocumentTypeIdRules(),
            'title' => ['nullable', 'string', 'max:200'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $rules['document_type_id'] = $this->requiredDocumentTypeIdRules();

        return $rules;
    }
}
