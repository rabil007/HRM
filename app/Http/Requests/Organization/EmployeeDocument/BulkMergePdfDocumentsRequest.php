<?php

namespace App\Http\Requests\Organization\EmployeeDocument;

use Illuminate\Foundation\Http\FormRequest;

class BulkMergePdfDocumentsRequest extends FormRequest
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
            'document_ids' => ['required', 'array', 'min:2'],
            'document_ids.*' => ['integer', 'distinct'],
            'download_name' => ['nullable', 'string', 'max:200', 'regex:/^[a-zA-Z0-9\-_]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_ids.min' => 'Select at least 2 PDF files to merge.',
        ];
    }
}
