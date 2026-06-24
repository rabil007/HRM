<?php

namespace App\Http\Requests\Organization\Department;

use App\Http\Requests\Organization\Department\Concerns\ValidatesDepartmentManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDepartmentRequest extends FormRequest
{
    use ValidatesDepartmentManager;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'parent_id' => ['nullable', 'integer', 'exists:departments,id'],
            'manager_id' => $this->managerIdRules($companyId),
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }
}
