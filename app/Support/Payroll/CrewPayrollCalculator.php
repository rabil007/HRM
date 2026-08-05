<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollWorkPeriodClassification;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CrewPayrollCalculator
{
    public function __construct(
        private readonly CrewOvertimePay $overtimePay,
    ) {}

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @param  array{
     *     days?: list<array<string, mixed>>,
     *     earning_periods?: list<array<string, mixed>>,
     *     presentation_lines?: list<array<string, mixed>>,
     *     requested_prior_days?: int,
     *     payable_prior_days?: int,
     *     current_days?: int,
     *     excluded_already_paid?: list<array<string, mixed>>,
     *     reserved_conflicts?: list<array<string, mixed>>,
     *     warnings?: list<string>,
     *     issues?: list<array<string, mixed>>
     * }|null  $allocationPlan
     * @return array{
     *     basic_salary: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     overtime_hours: float,
     *     bonus: string,
     *     other_deductions: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     working_days: int,
     *     present_days: float,
     *     leave_days: float,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    public function calculate(
        CrewTimesheet $timesheet,
        Collection $components,
        int $overtimePeriodDays,
        int $workingDaysInPeriod,
        ?array $allocationPlan = null,
    ): array {
        if ($allocationPlan !== null) {
            return $this->calculateFromAllocationPlan(
                $timesheet,
                $components,
                $overtimePeriodDays,
                $workingDaysInPeriod,
                $allocationPlan,
            );
        }

        return $this->calculateFromTimesheet(
            $timesheet,
            $components,
            $overtimePeriodDays,
            $workingDaysInPeriod,
        );
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @return array{
     *     basic_salary: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     overtime_hours: float,
     *     bonus: string,
     *     other_deductions: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     working_days: int,
     *     present_days: float,
     *     leave_days: float,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    private function calculateFromTimesheet(
        CrewTimesheet $timesheet,
        Collection $components,
        int $overtimePeriodDays,
        int $workingDaysInPeriod,
    ): array {
        $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic);
        $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance);
        $supplementaryRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance);

        $timesheet->loadMissing(['segments.assignment', 'segments.vessel', 'segments.client', 'segments.rank', 'period']);

        if ($timesheet->segments->isNotEmpty()) {
            // Never sum raw segment days when segments exist — prior-period portions would
            // inflate payable days. Clip to the payroll period (same rules as parent sync).
            $period = $timesheet->relationLoaded('period')
                ? $timesheet->period
                : $timesheet->period()->first();

            $clipped = $this->clipSegmentDaysToPeriod($timesheet->segments, $period);
            $signOnStandbyDays = $clipped[CrewTimesheetPayCategory::SignOnStandby->value];
            $signOffStandbyDays = $clipped[CrewTimesheetPayCategory::SignOffStandby->value];
            $onsiteDays = $clipped[CrewTimesheetPayCategory::Onsite->value];
            $standbyDays = round($signOnStandbyDays + $signOffStandbyDays, 2);
        } else {
            // Incomplete legacy flat-field pairs cannot contribute payable days.
            $signOnStandbyDays = $this->payableFlatCategoryDays(
                $timesheet->sign_on_standby_from,
                $timesheet->sign_on_standby_to,
                $timesheet->sign_on_standby_days,
            );
            $signOffStandbyDays = $this->payableFlatCategoryDays(
                $timesheet->sign_off_standby_from,
                $timesheet->sign_off_standby_to,
                $timesheet->sign_off_standby_days,
            );
            $onsiteDays = $this->payableFlatCategoryDays(
                $timesheet->onsite_from,
                $timesheet->onsite_to,
                $timesheet->onsite_days,
            );
            $standbyDays = round($signOnStandbyDays + $signOffStandbyDays, 2);
        }

        $overtimeHours = (float) ($timesheet->overtime_hours ?? 0);
        $hasPayableActivity = $standbyDays > 0 || $onsiteDays > 0 || $overtimeHours > 0;

        if ($basicRate === null && $hasPayableActivity) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic daily rate is required on the crew contract.',
            ]);
        }

        $basicRate ??= 0.0;
        $standbyDailyRate = $basicRate + ($supplementaryRate ?? 0);
        $signOnStandbyPay = round($signOnStandbyDays * $standbyDailyRate, 2);
        $signOffStandbyPay = round($signOffStandbyDays * $standbyDailyRate, 2);
        $standbyPay = round($signOnStandbyPay + $signOffStandbyPay, 2);

        $onsitePay = round($onsiteDays * $basicRate, 2);
        $siteAllowancePay = round($onsiteDays * ($siteRate ?? 0), 2);
        $supplementaryPay = round($onsiteDays * ($supplementaryRate ?? 0), 2);

        $overtimeBreakdown = $this->resolveOvertimePay(
            $overtimeHours,
            $overtimePeriodDays,
            $basicRate,
            $siteRate ?? 0.0,
            $supplementaryRate ?? 0.0,
        );
        $overtimePay = $overtimeBreakdown['overtime_pay'];

        $additionalAmount = round((float) ($timesheet->additional_amount ?? 0), 2);
        $deductionAmount = round((float) ($timesheet->deduction_amount ?? 0), 2);

        $grossSalary = round(
            $standbyPay + $onsitePay + $siteAllowancePay + $supplementaryPay + $overtimePay + $additionalAmount,
            2,
        );
        $netSalary = round($grossSalary - $deductionAmount, 2);
        $presentDays = round($standbyDays + $onsiteDays, 2);
        $leaveDays = round(max(0, $workingDaysInPeriod - $presentDays), 2);

        $lines = [
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_pay' => $standbyPay,
            'site_allowance' => $siteAllowancePay,
            'supplementary_allowance' => $supplementaryPay,
            'overtime' => $overtimePay,
            'additional' => $additionalAmount,
            'deduction' => $deductionAmount,
        ];

        $breakdown = [
            'salary_structure' => 'daily',
            'sign_on_standby_days' => $signOnStandbyDays,
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_days' => $onsiteDays,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_days' => $signOffStandbyDays,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_days' => $standbyDays,
            'total_standby_pay' => $standbyPay,
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'rates' => [
                'basic_daily' => $basicRate,
                'site_allowance_daily' => $siteRate ?? 0,
                'supplementary_allowance_daily' => $supplementaryRate ?? 0,
            ],
            'overtime' => $overtimeBreakdown,
            'lines' => $lines,
            'gross_salary' => $grossSalary,
            'net_salary' => $netSalary,
            'movement_segments' => $this->movementSegments($timesheet),
        ];

        return [
            'basic_salary' => $this->formatMoney($standbyPay + $onsitePay),
            'other_allowances' => $this->formatMoney($siteAllowancePay + $supplementaryPay),
            'overtime_pay' => $this->formatMoney($overtimePay),
            'overtime_hours' => $overtimeHours,
            'bonus' => $this->formatMoney($additionalAmount),
            'other_deductions' => $this->formatMoney($deductionAmount),
            'total_deductions' => $this->formatMoney($deductionAmount),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'calculation_breakdown' => $breakdown,
        ];
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     * @param  array{
     *     days?: list<array<string, mixed>>,
     *     earning_periods?: list<array<string, mixed>>,
     *     presentation_lines?: list<array<string, mixed>>,
     *     requested_prior_days?: int,
     *     payable_prior_days?: int,
     *     current_days?: int,
     *     excluded_already_paid?: list<array<string, mixed>>,
     *     reserved_conflicts?: list<array<string, mixed>>,
     *     warnings?: list<string>,
     *     issues?: list<array<string, mixed>>
     * }  $allocationPlan
     * @return array{
     *     basic_salary: string,
     *     other_allowances: string,
     *     overtime_pay: string,
     *     overtime_hours: float,
     *     bonus: string,
     *     other_deductions: string,
     *     total_deductions: string,
     *     gross_salary: string,
     *     net_salary: string,
     *     working_days: int,
     *     present_days: float,
     *     leave_days: float,
     *     calculation_breakdown: array<string, mixed>
     * }
     */
    private function calculateFromAllocationPlan(
        CrewTimesheet $timesheet,
        Collection $components,
        int $overtimePeriodDays,
        int $workingDaysInPeriod,
        array $allocationPlan,
    ): array {
        $basicRate = $this->activeAmount($components, SalaryComponentCode::Basic);
        $siteRate = $this->activeAmount($components, SalaryComponentCode::SiteAllowance);
        $supplementaryRate = $this->activeAmount($components, SalaryComponentCode::SupplementaryAllowance);

        $timesheet->loadMissing([
            'segments.assignment',
            'segments.vessel',
            'segments.client',
            'segments.rank',
        ]);

        /** @var list<array<string, mixed>> $days */
        $days = $allocationPlan['days'] ?? [];
        /** @var list<array<string, mixed>> $earningPeriods */
        $earningPeriods = $allocationPlan['earning_periods']
            ?? $allocationPlan['presentation_lines']
            ?? [];

        $currentDays = array_values(array_filter(
            $days,
            fn (array $day): bool => $this->isCurrentClassification($day['period_classification'] ?? null),
        ));
        $priorDays = array_values(array_filter(
            $days,
            fn (array $day): bool => $this->isPriorClassification($day['period_classification'] ?? null),
        ));

        $signOnStandbyDays = $this->countDaysByCategory($currentDays, CrewTimesheetPayCategory::SignOnStandby);
        $signOffStandbyDays = $this->countDaysByCategory($currentDays, CrewTimesheetPayCategory::SignOffStandby);
        $onsiteDays = $this->countDaysByCategory($currentDays, CrewTimesheetPayCategory::Onsite);
        $standbyDays = round($signOnStandbyDays + $signOffStandbyDays, 2);

        // Standby amounts store basic + supplementary separately; pay line is their sum.
        $signOnStandbyPay = round(
            $this->sumAmountsByCategory($currentDays, CrewTimesheetPayCategory::SignOnStandby, 'basic_amount')
            + $this->sumAmountsByCategory($currentDays, CrewTimesheetPayCategory::SignOnStandby, 'supplementary_allowance_amount'),
            2,
        );
        $signOffStandbyPay = round(
            $this->sumAmountsByCategory($currentDays, CrewTimesheetPayCategory::SignOffStandby, 'basic_amount')
            + $this->sumAmountsByCategory($currentDays, CrewTimesheetPayCategory::SignOffStandby, 'supplementary_allowance_amount'),
            2,
        );
        $standbyPay = round($signOnStandbyPay + $signOffStandbyPay, 2);
        $onsitePay = $this->sumAmountsByCategory($currentDays, CrewTimesheetPayCategory::Onsite, 'basic_amount');
        $siteAllowancePayCurrent = $this->sumDayField($currentDays, 'site_allowance_amount');
        $supplementaryPayCurrent = $this->sumAmountsByCategory(
            $currentDays,
            CrewTimesheetPayCategory::Onsite,
            'supplementary_allowance_amount',
        );

        $basicSalary = $this->sumDayField($days, 'basic_amount');
        $otherAllowances = round(
            $this->sumDayField($days, 'site_allowance_amount')
            + $this->sumDayField($days, 'supplementary_allowance_amount'),
            2,
        );
        $allocationAmount = $this->sumDayField($days, 'total_amount');

        $overtimeHours = (float) ($timesheet->overtime_hours ?? 0);
        $hasPayableActivity = $allocationAmount > 0 || $overtimeHours > 0;

        if ($basicRate === null && $overtimeHours > 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic daily rate is required on the crew contract.',
            ]);
        }

        if ($basicRate === null && $hasPayableActivity && $days === []) {
            throw ValidationException::withMessages([
                'employee_id' => 'Active basic daily rate is required on the crew contract.',
            ]);
        }

        $basicRate ??= 0.0;

        $overtimeBreakdown = $this->resolveOvertimePay(
            $overtimeHours,
            $overtimePeriodDays,
            $basicRate,
            $siteRate ?? 0.0,
            $supplementaryRate ?? 0.0,
        );
        $overtimePay = $overtimeBreakdown['overtime_pay'];

        $additionalAmount = round((float) ($timesheet->additional_amount ?? 0), 2);
        $deductionAmount = round((float) ($timesheet->deduction_amount ?? 0), 2);

        $grossSalary = round($allocationAmount + $overtimePay + $additionalAmount, 2);
        $netSalary = round($grossSalary - $deductionAmount, 2);
        $presentDays = round($standbyDays + $onsiteDays, 2);
        $leaveDays = round(max(0, $workingDaysInPeriod - $presentDays), 2);

        $priorBasic = $this->sumDayField($priorDays, 'basic_amount');
        $priorSite = $this->sumDayField($priorDays, 'site_allowance_amount');
        $priorSupplementary = $this->sumDayField($priorDays, 'supplementary_allowance_amount');
        $priorAmount = $this->sumDayField($priorDays, 'total_amount');

        $currentAmount = $this->sumDayField($currentDays, 'total_amount');
        $currentBasic = $this->sumDayField($currentDays, 'basic_amount');
        $currentSite = $this->sumDayField($currentDays, 'site_allowance_amount');
        $currentSupplementary = $this->sumDayField($currentDays, 'supplementary_allowance_amount');

        $priorEarningPeriods = array_values(array_filter(
            $earningPeriods,
            fn (array $line): bool => $this->isPriorClassification($line['period_classification'] ?? null),
        ));

        $lines = [
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_pay' => $standbyPay,
            'site_allowance' => $siteAllowancePayCurrent,
            'supplementary_allowance' => $supplementaryPayCurrent,
            'overtime' => $overtimePay,
            'additional' => $additionalAmount,
            'deduction' => $deductionAmount,
            'prior_period_amount' => $priorAmount,
            'allocation_amount' => $allocationAmount,
        ];

        $priorSummary = [
            'requested_days' => (int) ($allocationPlan['requested_prior_days'] ?? 0),
            'payable_days' => (int) ($allocationPlan['payable_prior_days'] ?? count($priorDays)),
            'days' => count($priorDays),
            'amount' => $priorAmount,
            'basic_amount' => $priorBasic,
            'site_allowance_amount' => $priorSite,
            'supplementary_allowance_amount' => $priorSupplementary,
            'excluded_already_paid' => $allocationPlan['excluded_already_paid'] ?? [],
            'reserved_conflicts' => $allocationPlan['reserved_conflicts'] ?? [],
            'warnings' => $allocationPlan['warnings'] ?? [],
        ];

        $breakdown = [
            'salary_structure' => 'daily',
            'sign_on_standby_days' => $signOnStandbyDays,
            'sign_on_standby_pay' => $signOnStandbyPay,
            'onsite_days' => $onsiteDays,
            'onsite_pay' => $onsitePay,
            'sign_off_standby_days' => $signOffStandbyDays,
            'sign_off_standby_pay' => $signOffStandbyPay,
            'total_standby_days' => $standbyDays,
            'total_standby_pay' => $standbyPay,
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'rates' => [
                'basic_daily' => $basicRate,
                'site_allowance_daily' => $siteRate ?? 0,
                'supplementary_allowance_daily' => $supplementaryRate ?? 0,
            ],
            'overtime' => $overtimeBreakdown,
            'lines' => $lines,
            'gross_salary' => $grossSalary,
            'net_salary' => $netSalary,
            'current_period' => [
                'total_standby_days' => $standbyDays,
                'sign_on_standby_days' => $signOnStandbyDays,
                'sign_off_standby_days' => $signOffStandbyDays,
                'onsite_days' => $onsiteDays,
                'days' => count($currentDays),
                'amount' => $currentAmount,
                'basic_amount' => $currentBasic,
                'site_allowance_amount' => $currentSite,
                'supplementary_allowance_amount' => $currentSupplementary,
            ],
            'prior_period' => $priorSummary,
            'prior_period_adjustments' => $priorEarningPeriods,
            'earning_periods' => $earningPeriods,
            'presentation_lines' => $earningPeriods,
            'allocation_lines' => $earningPeriods,
            'prior_period_lines' => $priorSummary,
            'movement_segments' => $this->movementSegments($timesheet),
        ];

        return [
            'basic_salary' => $this->formatMoney($basicSalary),
            'other_allowances' => $this->formatMoney($otherAllowances),
            'overtime_pay' => $this->formatMoney($overtimePay),
            'overtime_hours' => $overtimeHours,
            'bonus' => $this->formatMoney($additionalAmount),
            'other_deductions' => $this->formatMoney($deductionAmount),
            'total_deductions' => $this->formatMoney($deductionAmount),
            'gross_salary' => $this->formatMoney($grossSalary),
            'net_salary' => $this->formatMoney($netSalary),
            'working_days' => $workingDaysInPeriod,
            'present_days' => $presentDays,
            'leave_days' => $leaveDays,
            'calculation_breakdown' => $breakdown,
        ];
    }

    /**
     * Clip segment day counts to the payroll period (exclude prior-period arrears days).
     *
     * @param  Collection<int, CrewTimesheetSegment>  $segments
     * @return array<string, float>
     */
    private function clipSegmentDaysToPeriod(Collection $segments, mixed $period): array
    {
        $totals = [
            CrewTimesheetPayCategory::SignOnStandby->value => 0.0,
            CrewTimesheetPayCategory::Onsite->value => 0.0,
            CrewTimesheetPayCategory::SignOffStandby->value => 0.0,
        ];

        $periodStart = $period?->start_date !== null
            ? CarbonImmutable::parse($period->start_date)->startOfDay()
            : null;
        $periodEnd = $period?->end_date !== null
            ? CarbonImmutable::parse($period->end_date)->startOfDay()
            : null;

        foreach ($segments as $segment) {
            $category = $segment->pay_category instanceof CrewTimesheetPayCategory
                ? $segment->pay_category->value
                : (string) $segment->pay_category;

            if (! array_key_exists($category, $totals)
                || $segment->from_date === null
                || $segment->to_date === null) {
                continue;
            }

            $from = CarbonImmutable::parse($segment->from_date)->startOfDay();
            $to = CarbonImmutable::parse($segment->to_date)->startOfDay();

            if ($periodStart !== null && $periodEnd !== null) {
                $clippedFrom = $from->greaterThan($periodStart) ? $from : $periodStart;
                $clippedTo = $to->lessThan($periodEnd) ? $to : $periodEnd;

                if ($clippedFrom->greaterThan($clippedTo)) {
                    continue;
                }

                $from = $clippedFrom;
                $to = $clippedTo;
            }

            $totals[$category] += (float) ($from->diffInDays($to) + 1);
        }

        return [
            CrewTimesheetPayCategory::SignOnStandby->value => round($totals[CrewTimesheetPayCategory::SignOnStandby->value], 2),
            CrewTimesheetPayCategory::Onsite->value => round($totals[CrewTimesheetPayCategory::Onsite->value], 2),
            CrewTimesheetPayCategory::SignOffStandby->value => round($totals[CrewTimesheetPayCategory::SignOffStandby->value], 2),
        ];
    }

    private function isCurrentClassification(mixed $value): bool
    {
        return in_array((string) $value, [
            PayrollWorkPeriodClassification::Current->value,
            'current_period',
        ], true);
    }

    private function isPriorClassification(mixed $value): bool
    {
        return in_array((string) $value, [
            PayrollWorkPeriodClassification::Prior->value,
            'prior_period',
        ], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function movementSegments(CrewTimesheet $timesheet): array
    {
        return $timesheet->segments->map(fn ($segment) => [
            'id' => $segment->id,
            'sequence' => $segment->sequence,
            'pay_category' => $segment->pay_category?->value,
            'from_date' => $segment->from_date?->toDateString(),
            'to_date' => $segment->to_date?->toDateString(),
            'days' => (float) $segment->days,
            'source' => $segment->source?->value,
            'crew_assignment_id' => $segment->crew_assignment_id,
            'assignment_no' => $segment->assignment?->assignment_no,
            'vessel_id' => $segment->vessel_id,
            'vessel_name' => $segment->vessel?->name,
            'client_id' => $segment->client_id,
            'client_name' => $segment->client?->name,
            'rank_id' => $segment->rank_id,
            'rank_name' => $segment->rank?->name,
        ])->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function countDaysByCategory(array $days, CrewTimesheetPayCategory $category): float
    {
        return (float) count(array_filter(
            $days,
            fn (array $day): bool => ($day['pay_category'] ?? null) === $category->value,
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function sumAmountsByCategory(array $days, CrewTimesheetPayCategory $category, string $field): float
    {
        $total = 0.0;

        foreach ($days as $day) {
            if (($day['pay_category'] ?? null) !== $category->value) {
                continue;
            }

            $total += (float) ($day[$field] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $days
     */
    private function sumDayField(array $days, string $field): float
    {
        $total = 0.0;

        foreach ($days as $day) {
            $total += (float) ($day[$field] ?? 0);
        }

        return round($total, 2);
    }

    /**
     * @return array{
     *     hours: float,
     *     period_days: int,
     *     daily_onsite_rate: float,
     *     monthly_salary: float,
     *     hour_rate: float,
     *     overtime_hourly_rate: float,
     *     overtime_pay: float
     * }
     */
    private function resolveOvertimePay(
        float $overtimeHours,
        int $periodDays,
        float $basicDaily,
        float $siteDaily,
        float $supplementaryDaily,
    ): array {
        if ($overtimeHours <= 0) {
            return [
                'hours' => 0.0,
                'period_days' => $periodDays,
                'daily_onsite_rate' => 0.0,
                'monthly_salary' => 0.0,
                'hour_rate' => 0.0,
                'overtime_hourly_rate' => 0.0,
                'overtime_pay' => 0.0,
            ];
        }

        $dailyOnsiteRate = round($basicDaily + $siteDaily + $supplementaryDaily, 2);
        $overtimeMonthlySalary = CrewOvertimeMonthlySalary::fromDailyRates(
            $periodDays,
            $basicDaily,
            $siteDaily,
            $supplementaryDaily,
        );

        if ($periodDays <= 0 || $overtimeMonthlySalary <= 0) {
            throw ValidationException::withMessages([
                'employee_id' => 'Pay period days and active crew daily rates are required when overtime hours are entered.',
            ]);
        }

        return array_merge(
            $this->overtimePay->calculate($overtimeHours, $overtimeMonthlySalary),
            [
                'period_days' => $periodDays,
                'daily_onsite_rate' => $dailyOnsiteRate,
            ],
        );
    }

    /**
     * Incomplete legacy flat-field pairs (only one date set) contribute zero payable days.
     * Stored *_days values are ignored for those categories so they cannot create movement pay.
     */
    private function payableFlatCategoryDays(mixed $from, mixed $to, mixed $days): float
    {
        if (($from !== null && $to === null) || ($from === null && $to !== null)) {
            return 0.0;
        }

        return (float) ($days ?? 0);
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function activeAmount(Collection $components, SalaryComponentCode $code): ?float
    {
        $component = $components->first(
            fn (ContractSalaryComponent $item) => $item->component_code === $code
                && $item->status === SalaryComponentStatus::Active,
        );

        if ($component === null || (float) $component->amount <= 0) {
            return null;
        }

        return (float) $component->amount;
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }
}
