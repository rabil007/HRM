<?php

namespace App\Http\Requests\Organization\Department;

use App\Http\Requests\Organization\Department\Concerns\ValidatesDepartmentHierarchy;
use App\Http\Requests\Organization\Department\Concerns\ValidatesDepartmentManager;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDepartmentRequest extends FormRequest
{
    use ValidatesDepartmentHierarchy;
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
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(
                    fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'parent_id' => $this->parentIdRules($companyId),
            'manager_id' => $this->managerIdRules($companyId),
            'leave_approval_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('leave_approval_policies', 'id')->where(
                    fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $this->withHierarchyValidator($validator, $companyId);
    }
}
