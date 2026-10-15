<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkEmployeeTrainingDocumentRequest extends FormRequest
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
            'employee_document_id' => [
                'required',
                'integer',
                Rule::exists('employee_documents', 'id')->where(function ($query): void {
                    $query->where('company_id', (int) $this->attributes->get('current_company_id'));
                }),
            ],
            'confirm_replace_link' => ['sometimes', 'boolean'],
        ];
    }
}
