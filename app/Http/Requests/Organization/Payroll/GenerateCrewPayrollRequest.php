<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GenerateCrewPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.periods.update');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'excluded_employee_ids' => ['sometimes', 'array'],
            'excluded_employee_ids.*' => ['integer', ActiveCompanyEmployeeRule::exists($companyId, $this->user())],
            'employee_dates' => ['sometimes', 'array'],
            'employee_dates.*.start_date' => ['nullable', 'date'],
            'employee_dates.*.end_date' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $employeeDates = $this->input('employee_dates', []);

            if (! is_array($employeeDates)) {
                return;
            }

            foreach (array_keys($employeeDates) as $employeeId) {
                $employeeId = (int) $employeeId;

                if ($employeeId <= 0) {
                    $validator->errors()->add('employee_dates', 'One or more employee selections are invalid.');

                    return;
                }

                $rule = ActiveCompanyEmployeeRule::exists($companyId, $this->user());
                $passes = validator(
                    ['employee_id' => $employeeId],
                    ['employee_id' => $rule],
                )->passes();

                if (! $passes) {
                    $validator->errors()->add('employee_dates', 'One or more employee selections are invalid.');
                }
            }
        });
    }
}
