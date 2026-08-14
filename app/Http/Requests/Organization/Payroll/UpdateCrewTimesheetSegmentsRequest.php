<?php

namespace App\Http\Requests\Organization\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Attendance\CalculateLeaveRequestDays;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCrewTimesheetSegmentsRequest extends FormRequest
{
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
        if (! $this->has('segments') || ! is_array($this->input('segments'))) {
            return;
        }

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

            $segments[] = $segment;
        }

        $this->merge(['segments' => $segments]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'segments' => ['present', 'array'],
            'segments.*.pay_category' => [
                'required',
                'string',
                Rule::in([
                    CrewTimesheetPayCategory::SignOnStandby->value,
                    CrewTimesheetPayCategory::Onsite->value,
                    CrewTimesheetPayCategory::SignOffStandby->value,
                ]),
            ],
            'segments.*.from_date' => ['required', 'date'],
            'segments.*.to_date' => ['required', 'date'],
            'segments.*.days' => ['nullable', 'integer', 'min:0'],
            'segments.*.remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var PayrollPeriod|null $period */
            $period = $this->route('payrollPeriod');

            if (! $period instanceof PayrollPeriod) {
                return;
            }

            $periodEnd = $period->end_date?->toDateString();

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

                // Daily Crew may start before the payroll period (prior-period arrears).
                // Dates after the period end remain invalid.
                if ($periodEnd !== null && $end->toDateString() > $periodEnd) {
                    $validator->errors()->add(
                        "segments.{$index}.to_date",
                        'Movement period dates cannot extend past the payroll period end.',
                    );
                }

                if ($periodEnd !== null && $start->toDateString() > $periodEnd) {
                    $validator->errors()->add(
                        "segments.{$index}.from_date",
                        'Movement period dates cannot extend past the payroll period end.',
                    );
                }

                if (array_key_exists('days', $segment) && $segment['days'] !== null && $segment['days'] !== '') {
                    $expected = round((new CalculateLeaveRequestDays)((string) $from, (string) $to), 2);

                    if (abs((float) $segment['days'] - $expected) > 0.001) {
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
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function segments(): array
    {
        /** @var list<array<string, mixed>> $segments */
        $segments = $this->validated('segments');

        return array_map(function (array $segment): array {
            $from = $segment['from_date'] ?? null;
            $to = $segment['to_date'] ?? null;

            return [
                'pay_category' => $segment['pay_category'],
                'from_date' => $from,
                'to_date' => $to,
                'days' => filled($from) && filled($to)
                    ? (int) round((new CalculateLeaveRequestDays)((string) $from, (string) $to))
                    : null,
                'remarks' => $segment['remarks'] ?? null,
            ];
        }, $segments);
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
