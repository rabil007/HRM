<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveApprovalPolicyValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreLeaveApprovalPolicyRequest extends FormRequest
{
    use LeaveApprovalPolicyValidationRules;

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

        return $this->leaveApprovalPolicyRules($companyId);
    }

    public function withValidator(Validator $validator): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $this->withLeaveApprovalPolicyStepValidator($validator, $companyId);
    }
}
