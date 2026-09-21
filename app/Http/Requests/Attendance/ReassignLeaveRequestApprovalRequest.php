<?php

namespace App\Http\Requests\Attendance;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReassignLeaveRequestApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $leaveRequest = $this->route('leave_request');

        if (! $leaveRequest instanceof LeaveRequest) {
            return false;
        }

        $companyId = (int) $this->attributes->get('current_company_id');

        if ((int) $leaveRequest->company_id !== $companyId) {
            abort(404);
        }

        if (
            ! $user->can('attendance.leave-requests.view')
            || ! $user->can('attendance.leave-requests.view_all')
            || ! $user->can('attendance.leave-requests.reassign_approval')
        ) {
            abort(403);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reassignment_reason');

        if (is_string($reason)) {
            $this->merge([
                'reassignment_reason' => trim($reason),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'new_approver_employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
            'expected_approver_employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)),
            ],
            'reassignment_reason' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'new_approver_employee_id.exists' => 'The selected employee must be an active employee in this company.',
            'expected_approver_employee_id.required' => 'The current approver context is required. Refresh the request and try again.',
            'reassignment_reason.required' => 'A reassignment reason is required.',
        ];
    }
}
