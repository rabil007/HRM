<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Foundation\Http\FormRequest;

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

    protected function prepareForValidation(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $user = $this->user();

        if ($user === null || $companyId <= 0) {
            return;
        }

        if ($this->has('excluded_employee_ids') && is_array($this->input('excluded_employee_ids'))) {
            $this->merge([
                'excluded_employee_ids' => EmployeeVisibilityScope::filterAuthorizedEmployeeIds(
                    $user,
                    $companyId,
                    array_map(intval(...), $this->input('excluded_employee_ids')),
                ),
            ]);
        }

        if ($this->has('employee_dates') && is_array($this->input('employee_dates'))) {
            $authorizedIds = EmployeeVisibilityScope::filterAuthorizedEmployeeIds(
                $user,
                $companyId,
                array_map(intval(...), array_keys($this->input('employee_dates'))),
            );

            $this->merge([
                'employee_dates' => array_intersect_key(
                    $this->input('employee_dates'),
                    array_flip($authorizedIds),
                ),
            ]);
        }
    }
}
