<?php

namespace App\Support\Payroll;

use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Resolves unique payable calendar dates after company-local today for a
 * Daily Crew timesheet. Segment-backed timesheets should pass allocation-plan
 * days; legacy flat-field timesheets expand complete Sign-On / Onsite /
 * Sign-Off ranges using the same incomplete-pair rules as CrewPayrollCalculator.
 */
final class CollectFuturePayableCrewTimesheetDates
{
    /**
     * @param  list<array{work_date?: string|null}>  $allocationDays
     * @return list<string>
     */
    public function fromAllocationDays(array $allocationDays, CarbonInterface $today): array
    {
        $todayDate = CarbonImmutable::parse($today->toDateString())->toDateString();
        $dates = [];

        foreach ($allocationDays as $day) {
            $workDate = isset($day['work_date']) ? (string) $day['work_date'] : '';

            if ($workDate === '' || $workDate <= $todayDate) {
                continue;
            }

            $dates[$workDate] = true;
        }

        return $this->sortedKeys($dates);
    }

    /**
     * @return list<string>
     */
    public function fromLegacyFlatFields(
        CrewTimesheet $timesheet,
        PayrollPeriod $period,
        CarbonInterface $today,
    ): array {
        $todayDate = CarbonImmutable::parse($today->toDateString())->toDateString();
        $periodStart = CarbonImmutable::parse($period->start_date->toDateString())->startOfDay();
        $periodEnd = CarbonImmutable::parse($period->end_date->toDateString())->startOfDay();
        $dates = [];

        foreach ($this->legacyPayableRanges($timesheet) as [$from, $to]) {
            $clippedFrom = $from->greaterThan($periodStart) ? $from : $periodStart;
            $clippedTo = $to->lessThan($periodEnd) ? $to : $periodEnd;

            if ($clippedFrom->greaterThan($clippedTo)) {
                continue;
            }

            for ($cursor = $clippedFrom; $cursor->lessThanOrEqualTo($clippedTo); $cursor = $cursor->addDay()) {
                $workDate = $cursor->toDateString();

                if ($workDate <= $todayDate) {
                    continue;
                }

                $dates[$workDate] = true;
            }
        }

        return $this->sortedKeys($dates);
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function legacyPayableRanges(CrewTimesheet $timesheet): array
    {
        $checks = [
            [
                $timesheet->sign_on_standby_from,
                $timesheet->sign_on_standby_to,
                $timesheet->sign_on_standby_days,
            ],
            [
                $timesheet->onsite_from,
                $timesheet->onsite_to,
                $timesheet->onsite_days,
            ],
            [
                $timesheet->sign_off_standby_from,
                $timesheet->sign_off_standby_to,
                $timesheet->sign_off_standby_days,
            ],
        ];

        $ranges = [];

        foreach ($checks as [$from, $to, $days]) {
            if (! $this->contributesPayableFlatDays($from, $to, $days)) {
                continue;
            }

            $fromDate = CarbonImmutable::parse($from->toDateString())->startOfDay();
            $toDate = CarbonImmutable::parse($to->toDateString())->startOfDay();

            if ($toDate->lessThan($fromDate)) {
                continue;
            }

            $ranges[] = [$fromDate, $toDate];
        }

        return $ranges;
    }

    private function contributesPayableFlatDays(mixed $from, mixed $to, mixed $days): bool
    {
        // Match CrewPayrollCalculator::payableFlatCategoryDays.
        if (($from !== null && $to === null) || ($from === null && $to !== null)) {
            return false;
        }

        if ($from === null || $to === null) {
            return false;
        }

        return (float) ($days ?? 0) > 0;
    }

    /**
     * @param  array<string, true>  $dates
     * @return list<string>
     */
    private function sortedKeys(array $dates): array
    {
        $keys = array_keys($dates);
        sort($keys);

        return $keys;
    }
}
