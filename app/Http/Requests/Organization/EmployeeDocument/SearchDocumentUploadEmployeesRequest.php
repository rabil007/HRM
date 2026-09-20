<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class SearchDocumentUploadEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documents.upload') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:80'],
            'company_id' => ['prohibited'],
        ];
    }
}
