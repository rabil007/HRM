<?php

namespace App\Http\Requests\Attendance;

use App\Models\LeaveRequest;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdministrativelyDeleteLeaveRequestRequest extends FormRequest
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

        // In-company privileged permission gaps are 403; cross-company stays 404 above.
        if (
            ! $user->can('attendance.leave-requests.view')
            || ! $user->can('attendance.leave-requests.view_all')
            || ! $user->can('attendance.leave-requests.delete_any')
        ) {
            abort(403);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('administrative_deletion_reason');

        if (is_string($reason)) {
            $this->merge([
                'administrative_deletion_reason' => trim($reason),
            ]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'administrative_deletion_reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
