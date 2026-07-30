<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCrewTimesheetFinancialsRequest extends FormRequest
{
    /**
     * Non-nullable numeric columns on crew_timesheets.
     *
     * @var list<string>
     */
    private const NON_NULLABLE_NUMERIC_FIELDS = [
        'overtime_hours',
        'overtime_amount',
        'additional_amount',
        'deduction_amount',
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        $this->assertOwnedCrewTimesheetRoute();

        return $user->can('payroll.crew_timesheets.create')
            || $user->can('payroll.crew_timesheets.update');
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (self::NON_NULLABLE_NUMERIC_FIELDS as $field) {
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === '' || $value === null) {
                $normalized[$field] = 0;
            }
        }

        foreach (['unpaid_leave_days', 'remarks'] as $field) {
            if ($this->exists($field) && ($this->input($field) === '' || $this->input($field) === null)) {
                $normalized[$field] = null;
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unpaid_leave_days' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['sometimes', 'numeric', 'min:0'],
            'overtime_amount' => ['sometimes', 'numeric', 'min:0'],
            'additional_amount' => ['sometimes', 'numeric', 'min:0'],
            'deduction_amount' => ['sometimes', 'numeric', 'min:0'],
            'remarks' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function financialData(): array
    {
        $validated = $this->validated();
        $data = [];

        foreach ([
            'unpaid_leave_days',
            'overtime_hours',
            'overtime_amount',
            'additional_amount',
            'deduction_amount',
            'remarks',
        ] as $key) {
            if (array_key_exists($key, $validated)) {
                $data[$key] = $validated[$key];
            }
        }

        return $data;
    }

    private function assertOwnedCrewTimesheetRoute(): void
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        if ($companyId <= 0) {
            abort(404);
        }

        $period = $this->route('payrollPeriod');
        $timesheet = $this->route('timesheet');

        if (! $period instanceof PayrollPeriod || ! $timesheet instanceof CrewTimesheet) {
            abort(404);
        }

        if ((int) $period->company_id !== $companyId
            || ! $period->isCrew()
            || (int) $timesheet->company_id !== $companyId
            || (int) $timesheet->period_id !== (int) $period->id) {
            abort(404);
        }
    }
}
