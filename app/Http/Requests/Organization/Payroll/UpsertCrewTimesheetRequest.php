<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCrewTimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->can('payroll.crew_timesheets.create')
            || $this->user()?->can('payroll.crew_timesheets.update'));
    }

    protected function prepareForValidation(): void
    {
        $nullableFields = [
            'standby_from',
            'standby_to',
            'standby_days',
            'onsite_from',
            'onsite_to',
            'onsite_days',
            'remarks',
        ];

        $normalized = [];

        foreach ($nullableFields as $field) {
            $value = $this->input($field);

            if ($value === '' || $value === null) {
                $normalized[$field] = null;
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'period_id' => [
                'required',
                'integer',
                Rule::exists('payroll_periods', 'id')->where('company_id', $companyId),
            ],
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where('company_id', $companyId),
            ],
            'standby_from' => ['nullable', 'date'],
            'standby_to' => [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('standby_from') && $this->filled('standby_to'),
                    ['after_or_equal:standby_from'],
                ),
            ],
            'standby_days' => ['nullable', 'numeric', 'min:0'],
            'onsite_from' => ['nullable', 'date'],
            'onsite_to' => [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('onsite_from') && $this->filled('onsite_to'),
                    ['after_or_equal:onsite_from'],
                ),
            ],
            'onsite_days' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'additional_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function period(): PayrollPeriod
    {
        return PayrollPeriod::query()->findOrFail((int) $this->validated('period_id'));
    }

    public function employee(): Employee
    {
        return Employee::query()->findOrFail((int) $this->validated('employee_id'));
    }

    /**
     * @return array<string, mixed>
     */
    public function timesheetData(): array
    {
        $validated = $this->validated();

        return [
            'standby_from' => $validated['standby_from'] ?? null,
            'standby_to' => $validated['standby_to'] ?? null,
            'standby_days' => $validated['standby_days'] ?? null,
            'onsite_from' => $validated['onsite_from'] ?? null,
            'onsite_to' => $validated['onsite_to'] ?? null,
            'onsite_days' => $validated['onsite_days'] ?? null,
            'overtime_hours' => $validated['overtime_hours'] ?? 0,
            'additional_amount' => $validated['additional_amount'] ?? 0,
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }
}
