<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveTypeValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveTypeRequest extends FormRequest
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
        return $this->leaveTypeRules();
    }
}
