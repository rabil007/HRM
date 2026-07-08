<?php

namespace App\Http\Requests\Organization\Employee;

use App\Support\Employees\EmployeeExportFieldRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx'])],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*' => ['required', 'string', Rule::in(EmployeeExportFieldRegistry::allKeys())],
            'search' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'string', 'max:20'],
            'department_id' => ['nullable', 'string', 'max:20'],
            'position_id' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:50'],
            'manager_id' => ['nullable', 'string', 'max:20'],
            'gender_id' => ['nullable', 'string', 'max:20'],
            'nationality_id' => ['nullable', 'string', 'max:20'],
            'visa_type_id' => ['nullable', 'string', 'max:20'],
            'company_visa_type_id' => ['nullable', 'string', 'max:20'],
            'rank_id' => ['nullable', 'string', 'max:20'],
            'approval_location_id' => ['nullable', 'string', 'max:255'],
            'sssa_option_id' => ['nullable', 'string', 'max:255'],
            'crew_status' => ['nullable', 'string', 'max:50'],
            'role_id' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return list<string>
     */
    public function sanitizedFields(): array
    {
        /** @var list<string> $fields */
        $fields = $this->validated('fields');

        return EmployeeExportFieldRegistry::sanitizeKeys($fields, $this->user());
    }
}
