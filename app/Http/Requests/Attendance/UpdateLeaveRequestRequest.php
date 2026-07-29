<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveRequestValidationRules;
use App\Http\Requests\Attendance\Concerns\ValidatesLeaveRequestBalance;
use App\Http\Requests\Attendance\Concerns\ValidatesOverlappingLeaveRequests;
use App\Http\Requests\Attendance\Concerns\ValidatesOwnLeaveRequestEmployee;
use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveRequestAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeaveRequestRequest extends FormRequest
{
    use LeaveRequestValidationRules;
    use ValidatesLeaveRequestBalance;
    use ValidatesOverlappingLeaveRequests;
    use ValidatesOwnLeaveRequestEmployee;

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
        $authorization = app(LeaveRequestAuthorization::class);

        if ((int) $leaveRequest->company_id !== $companyId || ! $authorization->canView($leaveRequest, $user, $companyId)) {
            abort(404);
        }

        return $authorization->canAttemptEdit($leaveRequest, $user, $companyId);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->leaveRequestFieldRules();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOwnLeaveRequestEmployee($validator);
            $this->validateOverlappingLeaveRequests($validator);

            $leaveRequest = $this->route('leave_request');

            if ($leaveRequest instanceof LeaveRequest && $leaveRequest->status === 'pending') {
                $this->validateLeaveRequestBalance($validator, $leaveRequest->id);
            }
        });
    }
}
