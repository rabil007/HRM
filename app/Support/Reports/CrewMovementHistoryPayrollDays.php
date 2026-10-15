<?php

namespace App\Support\Reports;

use App\Enums\CrewTimesheetPayCategory;
use App\Support\Payroll\CrewTimeline\CrewPayableDateOwnership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewMovementHistoryPayrollDays
{
    /**
     * Build an assignment-wide preview using the same payable-date ownership
     * and collision priority as crew payroll allocation.
     *
     * Contract and payroll-period eligibility are intentionally outside this
     * report summary and remain part of payroll processing.
     *
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{
     *     sign_on_standby: array{periods: list<array{from: string, to: string, days: int}>, total_days: int},
     *     onsite: array{periods: list<array{from: string, to: string, days: int}>, total_days: int},
     *     sign_off_standby: array{periods: list<array{from: string, to: string, days: int}>, total_days: int},
     *     total_days: int
     * }
     */
    public static function summarize(
        Collection $phases,
        string $timezone,
        ?CarbonInterface $today = null,
        ?int $employeeId = null,
        ?int $companyId = null,
        ?int $assignmentId = null,
    ): array {
        $analysis = CrewPayableDateOwnership::analyze(
            $phases,
            $timezone,
            $today,
            clampToToday: false,
            employeeId: $employeeId,
            companyId: $companyId,
            assignmentId: $assignmentId,
        );

        /** @var array<string, list<string>> $datesByCategory */
        $datesByCategory = $analysis['dates_by_category'];

        $signOnStandby = self::categorySummary(
            $datesByCategory[CrewTimesheetPayCategory::SignOnStandby->value],
            $timezone,
        );
        $onsite = self::categorySummary(
            $datesByCategory[CrewTimesheetPayCategory::Onsite->value],
            $timezone,
        );
        $signOffStandby = self::categorySummary(
            $datesByCategory[CrewTimesheetPayCategory::SignOffStandby->value],
            $timezone,
        );

        return [
            CrewTimesheetPayCategory::SignOnStandby->value => $signOnStandby,
            CrewTimesheetPayCategory::Onsite->value => $onsite,
            CrewTimesheetPayCategory::SignOffStandby->value => $signOffStandby,
            'total_days' => $signOnStandby['total_days']
                + $onsite['total_days']
                + $signOffStandby['total_days'],
        ];
    }

    /**
     * @param  list<string>  $dates
     * @return array{periods: list<array{from: string, to: string, days: int}>, total_days: int}
     */
    private static function categorySummary(array $dates, string $timezone): array
    {
        if ($dates === []) {
            return [
                'periods' => [],
                'total_days' => 0,
            ];
        }

        $periods = [];
        $periodStart = $dates[0];
        $previousDate = $dates[0];
        $periodDays = 1;

        foreach (array_slice($dates, 1) as $date) {
            $expectedNextDate = CarbonImmutable::parse($previousDate, $timezone)
                ->addDay()
                ->toDateString();

            if ($date === $expectedNextDate) {
                $previousDate = $date;
                $periodDays++;

                continue;
            }

            $periods[] = [
                'from' => $periodStart,
                'to' => $previousDate,
                'days' => $periodDays,
            ];
            $periodStart = $date;
            $previousDate = $date;
            $periodDays = 1;
        }

        $periods[] = [
            'from' => $periodStart,
            'to' => $previousDate,
            'days' => $periodDays,
        ];

        return [
            'periods' => $periods,
            'total_days' => count($dates),
        ];
    }
}
