<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use App\Http\Requests\Organization\EmployeeDocument\Concerns\AppliesEmployeeDocumentTemplateRules;
use Illuminate\Foundation\Http\FormRequest;

class ReplaceEmployeeDocumentRequest extends FormRequest
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
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'document_number' => ['nullable', 'string', 'max:120'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
        ]);
    }
}
