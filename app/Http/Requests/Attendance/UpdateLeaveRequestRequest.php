<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveRequestValidationRules;
use App\Http\Requests\Attendance\Concerns\ValidatesOwnLeaveRequestEmployee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateLeaveRequestRequest extends FormRequest
{
    use LeaveRequestValidationRules;
    use ValidatesOwnLeaveRequestEmployee;

    public function authorize(): bool
    {
        return (bool) $this->user();
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
        });
    }
}
