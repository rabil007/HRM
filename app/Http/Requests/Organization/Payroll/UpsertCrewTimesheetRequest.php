<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;
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
            if (! $this->exists($field)) {
                continue;
            }

            $value = $this->input($field);

            if ($value === '' || $value === null) {
                $normalized[$field] = null;
            }
        }

        if ($this->has('segments') && is_array($this->input('segments'))) {
            $segments = [];

            foreach ($this->input('segments') as $segment) {
                if (! is_array($segment)) {
                    continue;
                }

                foreach (['from_date', 'to_date', 'remarks'] as $field) {
                    if (($segment[$field] ?? null) === '') {
                        $segment[$field] = null;
                    }
                }

                foreach (['vessel_id', 'client_id', 'rank_id'] as $field) {
                    if (($segment[$field] ?? null) === '' || ($segment[$field] ?? null) === null) {
                        $segment[$field] = null;
                    }
                }

                $segments[] = $segment;
            }

            $normalized['segments'] = $segments;
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
        $companyId = (int) $this->attributes->get('current_company_id');
        $hasSegments = $this->has('segments') && is_array($this->input('segments'));

        $rules = [
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
            'unpaid_leave_days' => ['nullable', 'numeric', 'min:0'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'additional_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];

        if ($hasSegments) {
            $rules['segments'] = ['required', 'array'];
            $rules['segments.*.pay_category'] = [
                'required',
                'string',
                Rule::in([
                    CrewTimesheetPayCategory::SignOnStandby->value,
                    CrewTimesheetPayCategory::Onsite->value,
                    CrewTimesheetPayCategory::SignOffStandby->value,
                ]),
            ];
            $rules['segments.*.from_date'] = ['required', 'date'];
            $rules['segments.*.to_date'] = ['required', 'date'];
            $rules['segments.*.days'] = ['nullable', 'integer', 'min:0'];
            $rules['segments.*.vessel_id'] = [
                'nullable',
                'integer',
                Rule::exists('vessels', 'id')->where('is_active', true),
            ];
            $rules['segments.*.client_id'] = [
                'nullable',
                'integer',
                Rule::exists('clients', 'id')->where('is_active', true),
            ];
            $rules['segments.*.rank_id'] = [
                'nullable',
                'integer',
                Rule::exists('ranks', 'id')->where('is_active', true),
            ];
            $rules['segments.*.remarks'] = ['nullable', 'string', 'max:1000'];
        } else {
            $rules['sign_on_standby_from'] = ['nullable', 'date'];
            $rules['sign_on_standby_to'] = [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('sign_on_standby_from') && $this->filled('sign_on_standby_to'),
                    ['after_or_equal:sign_on_standby_from'],
                ),
            ];
            $rules['onsite_from'] = ['nullable', 'date'];
            $rules['onsite_to'] = [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('onsite_from') && $this->filled('onsite_to'),
                    ['after_or_equal:onsite_from'],
                ),
            ];
            $rules['sign_off_standby_from'] = ['nullable', 'date'];
            $rules['sign_off_standby_to'] = [
                'nullable',
                'date',
                Rule::when(
                    $this->filled('sign_off_standby_from') && $this->filled('sign_off_standby_to'),
                    ['after_or_equal:sign_off_standby_from'],
                ),
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $period = PayrollPeriod::query()->find((int) $this->input('period_id'));

            if ($period === null) {
                return;
            }

            $periodStart = $period->start_date?->toDateString();
            $periodEnd = $period->end_date?->toDateString();
            $allowsPriorPeriodDates = $this->employeeAllowsPriorPeriodDates($period);

            if ($this->has('segments') && is_array($this->input('segments'))) {
                // Segment payloads are Daily Crew movement periods.
                $this->validateSegments($validator, $periodEnd, allowPriorPeriodDates: true);

                return;
            }

            $ranges = array_filter([
                'sign_on_standby' => $this->rangeFor('sign_on_standby_from', 'sign_on_standby_to'),
                'onsite' => $this->rangeFor('onsite_from', 'onsite_to'),
                'sign_off_standby' => $this->rangeFor('sign_off_standby_from', 'sign_off_standby_to'),
            ]);

            foreach ($ranges as $key => [$start, $end]) {
                if (! $allowsPriorPeriodDates && $periodStart !== null && $start->toDateString() < $periodStart) {
                    $validator->errors()->add("{$key}_from", 'Operational dates must fall within the payroll period.');
                }

                if ($periodEnd !== null && $end->toDateString() > $periodEnd) {
                    $validator->errors()->add(
                        "{$key}_to",
                        $allowsPriorPeriodDates
                            ? 'Operational dates cannot extend past the payroll period end.'
                            : 'Operational dates must fall within the payroll period.',
                    );
                }

                if ($periodEnd !== null && $start->toDateString() > $periodEnd) {
                    $validator->errors()->add(
                        "{$key}_from",
                        $allowsPriorPeriodDates
                            ? 'Operational dates cannot extend past the payroll period end.'
                            : 'Operational dates must fall within the payroll period.',
                    );
                }
            }

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

    private function validateSegments(Validator $validator, ?string $periodEnd, bool $allowPriorPeriodDates): void
    {
        /** @var list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}> $ranges */
        $ranges = [];

        foreach (array_values($this->input('segments', [])) as $index => $segment) {
            if (! is_array($segment)) {
                continue;
            }

            $from = $segment['from_date'] ?? null;
            $to = $segment['to_date'] ?? null;

            if (! filled($from) || ! filled($to)) {
                continue;
            }

            try {
                $start = CarbonImmutable::parse((string) $from)->startOfDay();
                $end = CarbonImmutable::parse((string) $to)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if ($end->lt($start)) {
                $validator->errors()->add(
                    "segments.{$index}.to_date",
                    'The To date must be on or after the From date.',
                );
            }

            if ($periodEnd !== null && $end->toDateString() > $periodEnd) {
                $validator->errors()->add(
                    "segments.{$index}.to_date",
                    $allowPriorPeriodDates
                        ? 'Movement period dates cannot extend past the payroll period end.'
                        : 'Movement period dates must fall within the payroll period.',
                );
            }

            if ($periodEnd !== null && $start->toDateString() > $periodEnd) {
                $validator->errors()->add(
                    "segments.{$index}.from_date",
                    $allowPriorPeriodDates
                        ? 'Movement period dates cannot extend past the payroll period end.'
                        : 'Movement period dates must fall within the payroll period.',
                );
            }

            if (array_key_exists('days', $segment) && $segment['days'] !== null && $segment['days'] !== '') {
                $expected = $this->inclusiveDays((string) $from, (string) $to);
                if ($expected !== null && abs((float) $segment['days'] - $expected) > 0.001) {
                    $validator->errors()->add(
                        "segments.{$index}.days",
                        'Movement period days must match the inclusive From/To date range.',
                    );
                }
            }

            // Overlap checks use the full submitted range (prior + current portions).
            $ranges[] = [$start, $end, $index];
        }

        for ($i = 0; $i < count($ranges); $i++) {
            for ($j = $i + 1; $j < count($ranges); $j++) {
                [$startA, $endA, $indexA] = $ranges[$i];
                [$startB, $endB, $indexB] = $ranges[$j];

                if ($startA->lte($endB) && $startB->lte($endA)) {
                    $validator->errors()->add(
                        "segments.{$indexB}.from_date",
                        'Movement periods cannot overlap on the same calendar dates.',
                    );
                    $validator->errors()->add(
                        "segments.{$indexA}.from_date",
                        'Movement periods cannot overlap on the same calendar dates.',
                    );
                }
            }
        }
    }

    private function employeeAllowsPriorPeriodDates(PayrollPeriod $period): bool
    {
        $employeeId = (int) $this->input('employee_id');

        if ($employeeId <= 0) {
            return false;
        }

        $employee = Employee::query()
            ->whereKey($employeeId)
            ->where('company_id', (int) $this->attributes->get('current_company_id'))
            ->first();

        if ($employee === null) {
            return false;
        }

        $contract = app(ResolveCrewContractForPayrollPeriod::class)->resolve($employee, $period);

        return $contract !== null
            && $contract->payroll_category === PayrollCategory::Crew
            && $contract->resolvedSalaryStructure() === ContractSalaryStructure::Daily;
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

        $data = [
            'overtime_hours' => $validated['overtime_hours'] ?? 0,
            'additional_amount' => $validated['additional_amount'] ?? 0,
            'deduction_amount' => $validated['deduction_amount'] ?? 0,
            'remarks' => $validated['remarks'] ?? null,
        ];

        if ($this->exists('unpaid_leave_days')) {
            $data['unpaid_leave_days'] = $validated['unpaid_leave_days'] ?? null;
        }

        if (isset($validated['segments']) && is_array($validated['segments'])) {
            $data['segments'] = array_map(function (array $segment): array {
                $from = $segment['from_date'] ?? null;
                $to = $segment['to_date'] ?? null;

                return [
                    'pay_category' => $segment['pay_category'],
                    'from_date' => $from,
                    'to_date' => $to,
                    'days' => $this->inclusiveDays(
                        is_string($from) ? $from : null,
                        is_string($to) ? $to : null,
                    ),
                    'vessel_id' => $segment['vessel_id'] ?? null,
                    'client_id' => $segment['client_id'] ?? null,
                    'rank_id' => $segment['rank_id'] ?? null,
                    'remarks' => $segment['remarks'] ?? null,
                ];
            }, $validated['segments']);

            return $data;
        }

        $operationalPairs = [
            ['sign_on_standby_from', 'sign_on_standby_to', 'sign_on_standby_days'],
            ['onsite_from', 'onsite_to', 'onsite_days'],
            ['sign_off_standby_from', 'sign_off_standby_to', 'sign_off_standby_days'],
        ];

        foreach ($operationalPairs as [$fromKey, $toKey, $daysKey]) {
            if (! $this->exists($fromKey) && ! $this->exists($toKey) && ! $this->exists($daysKey)) {
                continue;
            }

            $data[$fromKey] = $validated[$fromKey] ?? null;
            $data[$toKey] = $validated[$toKey] ?? null;
            $data[$daysKey] = $this->inclusiveDays(
                is_string($data[$fromKey] ?? null) ? $data[$fromKey] : null,
                is_string($data[$toKey] ?? null) ? $data[$toKey] : null,
            );
        }

        return $data;
    }

    private function inclusiveDays(?string $from, ?string $to): ?float
    {
        if (! filled($from) || ! filled($to)) {
            return null;
        }

        return round((new CalculateLeaveRequestDays)($from, $to), 2);
    }
}
