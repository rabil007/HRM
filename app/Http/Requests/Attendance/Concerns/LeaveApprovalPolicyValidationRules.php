<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Enums\LeaveApprovalApproverType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait LeaveApprovalPolicyValidationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function leaveApprovalPolicyRules(int $companyId): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.approver_type' => ['required', 'string', Rule::in(LeaveApprovalApproverType::values())],
            'steps.*.approver_employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(
                    fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'),
                ),
            ],
            'steps.*.is_required' => ['sometimes', 'boolean'],
        ];
    }

    protected function withLeaveApprovalPolicyStepValidator(Validator $validator, int $companyId): void
    {
        $validator->after(function (Validator $validator) use ($companyId): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $steps = $this->input('steps', []);

            if (! is_array($steps) || $steps === []) {
                $validator->errors()->add('steps', 'At least one approval step is required.');

                return;
            }

            $hasRequired = false;

            foreach (array_values($steps) as $index => $step) {
                if (! is_array($step)) {
                    continue;
                }

                $type = LeaveApprovalApproverType::tryFrom((string) ($step['approver_type'] ?? ''));
                $employeeId = $step['approver_employee_id'] ?? null;
                $isRequired = array_key_exists('is_required', $step)
                    ? filter_var($step['is_required'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                if ($isRequired) {
                    $hasRequired = true;
                }

                if ($type === null) {
                    continue;
                }

                if ($type->requiresEmployeeSelection() && blank($employeeId)) {
                    $validator->errors()->add(
                        "steps.{$index}.approver_employee_id",
                        'A specific employee is required for this approver type.',
                    );
                }

                if ($type === LeaveApprovalApproverType::DepartmentManager
                    || $type === LeaveApprovalApproverType::ParentManager) {
                    if (filled($employeeId)) {
                        $validator->errors()->add(
                            "steps.{$index}.approver_employee_id",
                            'Manager chain steps resolve automatically and should not select an employee.',
                        );
                    }
                }
            }

            if (! $hasRequired) {
                $validator->errors()->add('steps', 'At least one required approval step is required.');
            }

            unset($companyId);
        });
    }
}
