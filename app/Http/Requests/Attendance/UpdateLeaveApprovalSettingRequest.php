<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            Rule::exists('employees', 'id')->where(
                fn ($query) => $query->where('company_id', $companyId)->whereNull('deleted_at'),
            ),
        ];

        return [
            'default_hr_approver_employee_id' => $employeeRule,
            'fallback_approver_employee_id' => $employeeRule,
        ];
    }
}
