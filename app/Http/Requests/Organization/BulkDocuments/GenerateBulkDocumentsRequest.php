<?php

namespace App\Http\Requests\Organization\BulkDocuments;

use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateBulkDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('bulk_documents.generate') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $typeKeys = collect(BulkDocumentTypeRegistry::definitions())->pluck('key')->all();

        return [
            'document_type_key' => ['required', 'string', Rule::in($typeKeys)],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'distinct'],
            'search' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'string'],
            'department_id' => ['nullable', 'string'],
            'position_id' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'manager_id' => ['nullable', 'string'],
            'gender_id' => ['nullable', 'string'],
            'nationality_id' => ['nullable', 'string'],
            'visa_type_id' => ['nullable', 'string'],
            'company_visa_type_id' => ['nullable', 'string'],
            'rank_id' => ['nullable', 'string'],
            'approval_location_id' => ['nullable', 'string'],
            'sssa_option_id' => ['nullable', 'string'],
            'crew_status' => ['nullable', 'string'],
            'role_id' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->only([
            'search',
            'branch_id',
            'department_id',
            'position_id',
            'status',
            'manager_id',
            'gender_id',
            'nationality_id',
            'visa_type_id',
            'company_visa_type_id',
            'rank_id',
            'approval_location_id',
            'sssa_option_id',
            'crew_status',
            'role_id',
        ]);
    }

    /**
     * @return list<int>
     */
    public function employeeIds(): array
    {
        $ids = $this->input('employee_ids', []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_map('intval', $ids));
    }
}
