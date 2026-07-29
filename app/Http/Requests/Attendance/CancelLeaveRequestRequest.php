<?php

namespace App\Http\Requests\Attendance;

use App\Models\LeaveRequest;
use App\Support\Attendance\LeaveRequestAuthorization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelLeaveRequestRequest extends FormRequest
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
        $authorization = app(LeaveRequestAuthorization::class);

        if ((int) $leaveRequest->company_id !== $companyId || ! $authorization->canView($leaveRequest, $user, $companyId)) {
            abort(404);
        }

        return $authorization->canCancel($leaveRequest, $user, $companyId);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('cancellation_reason');

        if (is_string($reason)) {
            $this->merge([
                'cancellation_reason' => trim($reason),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
