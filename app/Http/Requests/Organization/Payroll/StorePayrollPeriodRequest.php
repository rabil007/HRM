<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\PayrollCategory;
use App\Support\Payroll\RegularPayrollPeriodKey;
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'payroll_category' => ['required', Rule::in(PayrollCategory::values())],
            'crew_timesheet_mode' => ['prohibited'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $categoryValue = $this->input('payroll_category');
            $startDate = $this->input('start_date');
            $endDate = $this->input('end_date');

            if (! is_string($categoryValue) || ! is_string($startDate) || ! is_string($endDate)) {
                return;
            }

            $category = PayrollCategory::tryFrom($categoryValue);

            if ($category === null) {
                return;
            }

            $companyId = (int) $this->attributes->get('current_company_id');
            $regularKey = RegularPayrollPeriodKey::tryFromDates(
                $companyId,
                $category,
                $startDate,
                $endDate,
            );

            if ($regularKey === null) {
                return;
            }

            $existing = RegularPayrollPeriodKey::findExisting($companyId, $regularKey);

            if ($existing === null) {
                return;
            }

            $validator->errors()->add(
                'start_date',
                sprintf(
                    'A regular %s payroll period for this month already exists (%s). Open the existing period instead of creating another.',
                    $category->label(),
                    $existing->name,
                ),
            );
        });
    }
}
