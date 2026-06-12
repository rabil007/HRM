<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveTypeValidationRules;
use App\Models\LeaveType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveTypeRequest extends FormRequest
{
    use LeaveTypeValidationRules;

    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $leaveType = $this->route('leave_type');

        return $this->leaveTypeRules($leaveType instanceof LeaveType ? $leaveType : null);
    }
}
