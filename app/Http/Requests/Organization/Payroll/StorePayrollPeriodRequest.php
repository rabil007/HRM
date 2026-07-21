<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\CrewTimesheetMode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'crew_timesheet_mode' => [
                'nullable',
                Rule::in(CrewTimesheetMode::values()),
                Rule::prohibitedIf(fn () => $this->input('payroll_category') === PayrollCategory::Office->value),
            ],
            'start_date' => [
                'required',
                'date',
                Rule::unique('payroll_periods', 'start_date')
                    ->where('company_id', $companyId)
                    ->where('payroll_category', $this->input('payroll_category'))
                    ->whereNot('status', PayrollPeriodStatus::Cancelled->value),
            ],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('payroll_category') === PayrollCategory::Office->value
                && filled($this->input('crew_timesheet_mode'))) {
                $validator->errors()->add(
                    'crew_timesheet_mode',
                    'Timesheet source applies only to crew pay periods.',
                );
            }
        });
    }
}
