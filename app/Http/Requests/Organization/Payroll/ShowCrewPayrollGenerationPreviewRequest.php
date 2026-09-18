<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Support\Employees\ActiveCompanyEmployeeRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowCrewPayrollGenerationPreviewRequest extends FormRequest
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
        ];
    }
}
