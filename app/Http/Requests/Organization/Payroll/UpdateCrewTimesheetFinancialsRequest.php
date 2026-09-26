<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Http\Requests\Organization\Payroll\Concerns\AssertsOwnedVisibleCrewTimesheetRoute;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCrewTimesheetFinancialsRequest extends FormRequest
{
    use AssertsOwnedVisibleCrewTimesheetRoute;

    /**
     * Operational (non-monetary) timesheet fields.
     *
     * @var list<string>
     */
    public const OPERATIONAL_FIELDS = [
        'unpaid_leave_days',
        'overtime_hours',
        'remarks',
    ];

    /**
     * Manually entered monetary adjustment fields.
     *
     * @var list<string>
     */
    public const MONETARY_FIELDS = [
        'overtime_amount',
        'additional_amount',
        'deduction_amount',
    ];

    /**
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

        $this->assertOwnedVisibleCrewTimesheetRoute();

        if (! ($user->can('payroll.crew_timesheets.create')
            || $user->can('payroll.crew_timesheets.update'))) {
            return false;
        }

        if ($this->requestsMonetaryFields() && ! $user->can('payroll.periods.update')) {
            return false;
        }

        return true;
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->requestsMonetaryFields()) {
                return;
            }

            if ($this->user()?->can('payroll.periods.update')) {
                return;
            }

            foreach (self::MONETARY_FIELDS as $field) {
                if ($this->exists($field)) {
                    $validator->errors()->add(
                        $field,
                        'You are not authorized to update payroll monetary fields on crew timesheets.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function financialData(): array
    {
        $validated = $this->validated();
        $data = [];
        $mayEditMoney = (bool) $this->user()?->can('payroll.periods.update');

        foreach ([...self::OPERATIONAL_FIELDS, ...self::MONETARY_FIELDS] as $key) {
            if (! array_key_exists($key, $validated)) {
                continue;
            }

            if (in_array($key, self::MONETARY_FIELDS, true) && ! $mayEditMoney) {
                continue;
            }

            $data[$key] = $validated[$key];
        }

        return $data;
    }

    private function requestsMonetaryFields(): bool
    {
        foreach (self::MONETARY_FIELDS as $field) {
            if ($this->exists($field)) {
                return true;
            }
        }

        return false;
    }
}
