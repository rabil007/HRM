<?php

namespace App\Http\Requests\Attendance;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveApprovalSettingRequest extends FormRequest
{
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

        $employeeRule = [
            'nullable',
            'integer',
            ActiveCompanyEmployeeRule::exists($companyId),
        ];

        return [
            'default_hr_approver_employee_id' => $employeeRule,
            'fallback_approver_employee_id' => $employeeRule,
            'email_notifications_enabled' => ['required', 'boolean'],
            'notify_on_submission' => ['required', 'boolean'],
            'notify_on_update' => ['required', 'boolean'],
            'notify_next_approver' => ['required', 'boolean'],
            'notify_on_final_decision' => ['required', 'boolean'],
            'copy_deciding_approver' => ['required', 'boolean'],
        ];
    }
}
