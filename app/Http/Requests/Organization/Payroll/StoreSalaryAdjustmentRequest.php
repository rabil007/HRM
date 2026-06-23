<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\SalaryAdjustmentType;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSalaryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.adjustments.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('period_id') === '' || $this->input('period_id') === null) {
            $this->merge(['period_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'period_id' => [
                'nullable',
                'integer',
                Rule::exists('payroll_periods', 'id')->where(fn ($query) => $query->where('company_id', $companyId)),
            ],
            'type' => ['required', Rule::in(SalaryAdjustmentType::values())],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail((int) $this->validated('employee_id'));
    }

    public function period(): ?PayrollPeriod
    {
        $periodId = $this->validated('period_id');

        if ($periodId === null) {
            return null;
        }

        return PayrollPeriod::query()->findOrFail((int) $periodId);
    }
}
