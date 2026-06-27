<?php

namespace App\Http\Requests\Organization\Payroll;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSalaryInputTypeStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->can('payroll.salary_inputs.update') ?? false)
            || ($this->user()?->can('payroll.periods.update') ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
