<?php

namespace App\Support\Reports;

use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignmentPhase;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class CrewMovementHistoryPayrollDays
{
    /**
     * Build an assignment-wide preview using the same inclusive calendar-day
     * categories and collision priority as crew payroll allocation.
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
    ): array {
        $today ??= now($timezone);
        $categoryResolver = new CrewPhasePayCategoryResolver;

        /** @var array<string, array{category: CrewTimesheetPayCategory, priority: int, phase_id: int}> $allocatedByDate */
        $allocatedByDate = [];

        foreach ($phases as $phase) {
            if ($phase->actual_start_at === null) {
                continue;
            }

            $end = match (true) {
                $phase->actual_end_at !== null => $phase->actual_end_at,
                $phase->status === CrewPhaseStatus::Active => $today,
                default => null,
            };

            if ($end === null || $end->lt($phase->actual_start_at)) {
                continue;
            }

            $from = self::localDate($phase->actual_start_at, $timezone);
            $to = self::localDate($end, $timezone);
            $category = $categoryResolver->resolve($phase->phase_code);
            $claim = [
                'category' => $category,
                'priority' => $categoryResolver->priority($category),
                'phase_id' => (int) $phase->id,
            ];

            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                $dateKey = $date->toDateString();
                $existing = $allocatedByDate[$dateKey] ?? null;

                if (
                    $existing === null
                    || $claim['priority'] > $existing['priority']
                    || (
                        $claim['priority'] === $existing['priority']
                        && $claim['phase_id'] < $existing['phase_id']
                    )
                ) {
                    $allocatedByDate[$dateKey] = $claim;
                }
            }
        }

        /** @var array<string, list<string>> $datesByCategory */
        $datesByCategory = [
            CrewTimesheetPayCategory::SignOnStandby->value => [],
            CrewTimesheetPayCategory::Onsite->value => [],
            CrewTimesheetPayCategory::SignOffStandby->value => [],
        ];

        ksort($allocatedByDate);

        foreach ($allocatedByDate as $date => $allocation) {
            $category = $allocation['category']->value;

            if (array_key_exists($category, $datesByCategory)) {
                $datesByCategory[$category][] = $date;
            }
        }

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

    private static function localDate(CarbonInterface $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $value->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();
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
