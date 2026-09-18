<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDocumentActionRequest extends FormRequest
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
        $companyId = (int) $this->attributes->get('current_company_id');
        $typeKeys = collect(BulkDocumentTypeRegistry::definitions())->pluck('key')->all();

        return [
            'document_type_key' => ['required', 'string', Rule::in($typeKeys)],
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'distinct', ActiveCompanyEmployeeRule::exists($companyId, $this->user())],
        ];
    }

    /**
     * @return list<int>
     */
    public function employeeIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('employee_ids', [])));
    }
}
