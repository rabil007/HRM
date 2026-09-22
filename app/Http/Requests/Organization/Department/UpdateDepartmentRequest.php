<?php

namespace App\Http\Requests\Organization\Department;

use App\Http\Requests\Organization\Department\Concerns\ValidatesDepartmentHierarchy;
use App\Http\Requests\Organization\Department\Concerns\ValidatesDepartmentManager;
use App\Models\Department;
use App\Support\Attendance\DepartmentAttendanceLeaveGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDepartmentRequest extends FormRequest
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
                    fn ($q) => $q
                        ->where('company_id', $companyId)
                        ->where('status', 'active')
                        ->whereNull('deleted_at'),
                ),
            ],
            'name' => ['required', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'in:active,inactive'],
            'include_in_attendance_leave' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        /** @var Department|null $department */
        $department = $this->route('department');
        $departmentId = $department instanceof Department ? (int) $department->id : null;

        $this->withHierarchyValidator($validator, $companyId, $departmentId);

        $validator->after(function (Validator $validator) use ($companyId, $department): void {
            if ($validator->errors()->isNotEmpty() || ! $department instanceof Department) {
                return;
            }

            if ((int) $department->company_id !== $companyId) {
                return;
            }

            $include = $this->boolean('include_in_attendance_leave');

            if (! $this->has('include_in_attendance_leave') || $include || ! (bool) $department->include_in_attendance_leave) {
                return;
            }

            $message = DepartmentAttendanceLeaveGuard::cannotExcludeDepartment($companyId, $department);

            if ($message !== null) {
                $validator->errors()->add('include_in_attendance_leave', $message);
            }
        });
    }
}
