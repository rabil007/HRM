<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Attendance\CalculateLeaveRequestDays;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
            'sign_on_standby_from',
            'sign_on_standby_to',
            'onsite_from',
            'onsite_to',
            'sign_off_standby_from',
            'sign_off_standby_to',
            'unpaid_leave_days',
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
                Rule::exists('employees', 'id')->where(fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active')),
            ],
            'sign_on_standby_from' => ['nullable', 'date'],
            'sign_on_standby_to' => [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('sign_on_standby_from') && $this->filled('sign_on_standby_to'),
                    ['after_or_equal:sign_on_standby_from'],
                ),
            ],
            'onsite_from' => ['nullable', 'date'],
            'onsite_to' => [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('onsite_from') && $this->filled('onsite_to'),
                    ['after_or_equal:onsite_from'],
                ),
            ],
            'sign_off_standby_from' => ['nullable', 'date'],
            'sign_off_standby_to' => [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('sign_off_standby_from') && $this->filled('sign_off_standby_to'),
                    ['after_or_equal:sign_off_standby_from'],
                ),
            ],
            'unpaid_leave_days' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'additional_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ranges = array_filter([
                'sign_on_standby' => $this->rangeFor('sign_on_standby_from', 'sign_on_standby_to'),
                'onsite' => $this->rangeFor('onsite_from', 'onsite_to'),
                'sign_off_standby' => $this->rangeFor('sign_off_standby_from', 'sign_off_standby_to'),
            ]);

            $keys = array_keys($ranges);

            foreach ($keys as $i => $keyA) {
                foreach (array_slice($keys, $i + 1) as $keyB) {
                    [$startA, $endA] = $ranges[$keyA];
                    [$startB, $endB] = $ranges[$keyB];

                    if ($startA <= $endB && $startB <= $endA) {
                        $validator->errors()->add(
                            $keyB.'_from',
                            'Sign-On Standby, Onsite and Sign-Off Standby date ranges cannot overlap.',
                        );
                    }
                }
            }
        });
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function rangeFor(string $fromKey, string $toKey): ?array
    {
        $from = $this->input($fromKey);
        $to = $this->input($toKey);

        if (! filled($from) || ! filled($to)) {
            return null;
        }

        try {
            return [CarbonImmutable::parse($from)->startOfDay(), CarbonImmutable::parse($to)->startOfDay()];
        } catch (\Throwable) {
            return null;
        }
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
            'sign_on_standby_from' => $validated['sign_on_standby_from'] ?? null,
            'sign_on_standby_to' => $validated['sign_on_standby_to'] ?? null,
            'sign_on_standby_days' => $this->inclusiveDays(
                $validated['sign_on_standby_from'] ?? null,
                $validated['sign_on_standby_to'] ?? null,
            ),
            'onsite_from' => $validated['onsite_from'] ?? null,
            'onsite_to' => $validated['onsite_to'] ?? null,
            'onsite_days' => $this->inclusiveDays(
                $validated['onsite_from'] ?? null,
                $validated['onsite_to'] ?? null,
            ),
            'sign_off_standby_from' => $validated['sign_off_standby_from'] ?? null,
            'sign_off_standby_to' => $validated['sign_off_standby_to'] ?? null,
            'sign_off_standby_days' => $this->inclusiveDays(
                $validated['sign_off_standby_from'] ?? null,
                $validated['sign_off_standby_to'] ?? null,
            ),
            'unpaid_leave_days' => $validated['unpaid_leave_days'] ?? null,
            'overtime_hours' => $validated['overtime_hours'] ?? 0,
            'additional_amount' => $validated['additional_amount'] ?? 0,
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'remarks' => $validated['remarks'] ?? null,
        ];
    }

    private function inclusiveDays(?string $from, ?string $to): ?float
    {
        if (! filled($from) || ! filled($to)) {
            return null;
        }

        return round((new CalculateLeaveRequestDays)($from, $to), 2);
    }
}
