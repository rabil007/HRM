<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class ExtractEmployeeDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.ai.use') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('company_id');
        $this->request->remove('employee_id');
    }
}
