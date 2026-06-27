<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Http\Requests\Organization\Payroll\Concerns\SalaryInputTypeValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSalaryInputTypeRequest extends FormRequest
{
    use SalaryInputTypeValidationRules;

    public function authorize(): bool
    {
        return ($this->user()?->can('payroll.salary_inputs.create') ?? false)
            || ($this->user()?->can('payroll.periods.update') ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->salaryInputTypeRules();
    }
}
