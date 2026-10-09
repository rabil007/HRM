<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ValidateCrewTimesheetOperationalIntegrity
{
    public function handle(
        CrewTimesheet $timesheet,
        Employee $employee,
        ?PayrollPeriod $period = null,
    ): CrewTimesheetOperationalIntegrityResult {
        $timesheet->loadMissing(['segments']);

        if ($timesheet->segments->isNotEmpty()) {
            return $this->validateMovements($timesheet, $employee);
        }

        return $this->validateFlatFields($timesheet, $employee, $period);
    }

    private function validateFlatFields(
        CrewTimesheet $timesheet,
        Employee $employee,
        ?PayrollPeriod $period = null,
    ): CrewTimesheetOperationalIntegrityResult {
        $checks = [
            [
                'sign_on_standby_from',
                'sign_on_standby_to',
                'sign_on_standby_days',
                'Sign-On Standby',
                CrewTimesheetPayCategory::SignOnStandby->value,
            ],
            [
                'onsite_from',
                'onsite_to',
                'onsite_days',
                'Onsite',
                CrewTimesheetPayCategory::Onsite->value,
            ],
            [
                'sign_off_standby_from',
                'sign_off_standby_to',
                'sign_off_standby_days',
                'Sign-Off Standby',
                CrewTimesheetPayCategory::SignOffStandby->value,
            ],
        ];

        $blocking = [];
        $warnings = [];
        $hasIncompleteMovementDates = false;
        $incompletePayCategory = null;

        foreach ($checks as [$fromKey, $toKey, $daysKey, $label, $payCategory]) {
            $from = $timesheet->{$fromKey};
            $to = $timesheet->{$toKey};
            $days = $timesheet->{$daysKey};

            if (($from !== null && $to === null) || ($from === null && $to !== null)) {
                $hasIncompleteMovementDates = true;
                $incompletePayCategory ??= $payCategory;

                continue;
            }

            if ($from !== null && $to !== null && $to->lt($from)) {
                $blocking[] = $this->finding(
                    'invalid_movement_range',
                    "{$employee->name} has an invalid {$label} date range.",
                    $payCategory,
                    'blocking',
                );
            }

            if ($days !== null && (float) $days < 0) {
                $blocking[] = $this->finding(
                    'negative_movement_days',
                    "{$employee->name} has negative {$label} days.",
                    $payCategory,
                    'blocking',
                );
            }
        }

        if ($hasIncompleteMovementDates) {
            $warnings[] = $this->finding(
                'incomplete_movement_range',
                'Incomplete movement dates.',
                $incompletePayCategory,
                'warning',
            );
        }

        if ($timesheet->unpaid_leave_days !== null && (float) $timesheet->unpaid_leave_days < 0) {
            $blocking[] = $this->finding(
                'negative_unpaid_leave_days',
                "{$employee->name} has negative unpaid leave days.",
                null,
                'blocking',
            );
        }

        $ranges = [];

        foreach ($checks as [$fromKey, $toKey, , $label, $payCategory]) {
            $from = $timesheet->{$fromKey};
            $to = $timesheet->{$toKey};

            if ($from !== null && $to !== null) {
                $ranges[] = [$from, $to, $label, $payCategory];
            }
        }

        for ($i = 0; $i < count($ranges); $i++) {
            for ($j = $i + 1; $j < count($ranges); $j++) {
                [$fromA, $toA, $labelA, $payCategoryA] = $ranges[$i];
                [$fromB, $toB, $labelB] = $ranges[$j];

                if ($fromA->lte($toB) && $fromB->lte($toA)) {
                    $blocking[] = $this->finding(
                        'overlapping_movement_ranges',
                        "{$employee->name} has overlapping {$labelA} and {$labelB} date ranges.",
                        $payCategoryA,
                        'blocking',
                    );
                }
            }
        }

        if ($period !== null) {
            array_push(
                $blocking,
                ...$this->flatFieldPeriodBoundaryFindings($timesheet, $employee, $period, $checks),
            );
        }

        return new CrewTimesheetOperationalIntegrityResult(
            blocking: $blocking,
            warnings: $warnings,
        );
    }

    /**
     * Reject complete payable legacy flat ranges that extend past the payroll period end.
     * Ranges may start before the period (same permitted-period rule as segment prior arrears).
     * Incomplete pairs remain non-payable warnings handled above — they are not period blockers.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $checks
     * @return list<array{code: string, message: string, pay_category: string|null, severity: 'blocking'|'warning'}>
     */
    private function flatFieldPeriodBoundaryFindings(
        CrewTimesheet $timesheet,
        Employee $employee,
        PayrollPeriod $period,
        array $checks,
    ): array {
        if ($period->start_date === null || $period->end_date === null) {
            return [];
        }

        $periodStart = CarbonImmutable::parse($period->start_date->toDateString())->startOfDay();
        $periodEnd = CarbonImmutable::parse($period->end_date->toDateString())->startOfDay();
        $findings = [];

        foreach ($checks as [$fromKey, $toKey, $daysKey, $label, $payCategory]) {
            $from = $timesheet->{$fromKey};
            $to = $timesheet->{$toKey};
            $days = $timesheet->{$daysKey};

            if (! $this->contributesPayableFlatDays($from, $to, $days)) {
                continue;
            }

            $fromDate = CarbonImmutable::parse($from->toDateString())->startOfDay();
            $toDate = CarbonImmutable::parse($to->toDateString())->startOfDay();

            if ($toDate->lessThan($fromDate)) {
                continue;
            }

            // Mirror segment allocation: dates after the period end are rejected.
            // Starting before period start remains permitted.
            if ($fromDate->greaterThan($periodEnd) || $toDate->greaterThan($periodEnd)) {
                $findings[] = $this->finding(
                    'legacy_movement_outside_payroll_period',
                    sprintf(
                        "%s's %s dates (%s) fall outside this payroll period (%s). Correct the movement dates or use the appropriate payroll period.",
                        $employee->name,
                        $label,
                        $this->formatInclusiveDateRange($fromDate, $toDate),
                        $this->formatInclusiveDateRange($periodStart, $periodEnd),
                    ),
                    $payCategory,
                    'blocking',
                );
            }
        }

        return $findings;
    }

    private function contributesPayableFlatDays(mixed $from, mixed $to, mixed $days): bool
    {
        // Match CrewPayrollCalculator::payableFlatCategoryDays / CollectFuturePayableCrewTimesheetDates.
        if (($from !== null && $to === null) || ($from === null && $to !== null)) {
            return false;
        }

        if ($from === null || $to === null) {
            return false;
        }

        return (float) ($days ?? 0) > 0;
    }

    private function formatInclusiveDateRange(CarbonInterface $from, CarbonInterface $to): string
    {
        return sprintf('%s – %s', $from->format('d M'), $to->format('d M Y'));
    }

    private function validateMovements(
        CrewTimesheet $timesheet,
        Employee $employee,
    ): CrewTimesheetOperationalIntegrityResult {
        /** @var list<array{0: CarbonInterface, 1: CarbonInterface, 2: string, 3: string|null}> $ranges */
        $ranges = [];
        $blocking = [];

        foreach ($timesheet->segments as $segment) {
            /** @var CrewTimesheetSegment $segment */
            $payCategory = $segment->pay_category?->value;

            if ($segment->from_date === null || $segment->to_date === null) {
                $blocking[] = $this->finding(
                    'missing_segment_dates',
                    "{$employee->name} has a movement period missing from/to dates.",
                    $payCategory,
                    'blocking',
                );

                continue;
            }

            if ($segment->to_date->lt($segment->from_date)) {
                $blocking[] = $this->finding(
                    'invalid_movement_range',
                    "{$employee->name} has a movement period with end before start.",
                    $payCategory,
                    'blocking',
                );

                continue;
            }

            if ((float) $segment->days < 0) {
                $blocking[] = $this->finding(
                    'negative_movement_days',
                    "{$employee->name} has a movement period with negative days.",
                    $payCategory,
                    'blocking',
                );
            }

            $label = $segment->pay_category?->label() ?? 'Movement';
            $ranges[] = [$segment->from_date, $segment->to_date, $label, $payCategory];
        }

        if ($timesheet->unpaid_leave_days !== null && (float) $timesheet->unpaid_leave_days < 0) {
            $blocking[] = $this->finding(
                'negative_unpaid_leave_days',
                "{$employee->name} has negative unpaid leave days.",
                null,
                'blocking',
            );
        }

        for ($i = 0; $i < count($ranges); $i++) {
            for ($j = $i + 1; $j < count($ranges); $j++) {
                [$fromA, $toA, $labelA, $payCategoryA] = $ranges[$i];
                [$fromB, $toB, $labelB] = $ranges[$j];

                if ($fromA->lte($toB) && $fromB->lte($toA)) {
                    $blocking[] = $this->finding(
                        'overlapping_movement_ranges',
                        "{$employee->name} has overlapping {$labelA} and {$labelB} movement periods.",
                        $payCategoryA,
                        'blocking',
                    );
                }
            }
        }

        return new CrewTimesheetOperationalIntegrityResult(
            blocking: $blocking,
            warnings: [],
        );
    }

    /**
     * @param  'blocking'|'warning'  $severity
     * @return array{code: string, message: string, pay_category: string|null, severity: 'blocking'|'warning'}
     */
    private function finding(
        string $code,
        string $message,
        ?string $payCategory,
        string $severity,
    ): array {
        return [
            'code' => $code,
            'message' => $message,
            'pay_category' => $payCategory,
            'severity' => $severity,
        ];
    }
}
