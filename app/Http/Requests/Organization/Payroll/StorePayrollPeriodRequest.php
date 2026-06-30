<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('payroll.periods.create');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'name' => ['required', 'string', 'max:100'],
            'payroll_category' => ['required', Rule::in(PayrollCategory::values())],
            'start_date' => [
                'required',
                'date',
                Rule::unique('payroll_periods', 'start_date')
                    ->where('company_id', $companyId)
                    ->where('payroll_category', $this->input('payroll_category'))
                    ->whereNot('status', PayrollPeriodStatus::Cancelled->value),
            ],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'payment_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
