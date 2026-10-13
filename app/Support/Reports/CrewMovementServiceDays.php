<?php

namespace App\Support\Reports;

use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewAssignmentPhase;
use App\Support\Payroll\CrewTimeline\CrewPhasePayCategoryResolver;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Distinct inclusive calendar dates for operational service totals.
 *
 * A phase handoff often closes one phase and opens the next on the same
 * company-local date. Each phase span still includes that date, but an
 * aggregate total counts the date once. Priority matches the payroll preview
 * (onsite, then sign-off standby, then sign-on standby). Excluded phases
 * (P0, P1, P6) never take a date from a service category.
 *
 * Dates after company-local today are not counted. The payroll preview is a
 * separate calculation and is not clamped here, so a future actual end can
 * make the payroll preview larger than these operational totals.
 *
 * Same-timestamp and midnight boundaries use the company-local calendar date
 * of each actual timestamp. A zero-length phase on one calendar date is one
 * eligible day for that phase, then subject to the same single-date priority.
 */
final class CrewMovementServiceDays
{
    /**
     * @param  Collection<int, CrewAssignmentPhase>  $phases
     * @return array{
     *     recorded: bool,
     *     future_actual_only: bool,
     *     has_future_actual: bool,
     *     vessel_service_days: int|null,
     *     standby_service_days: int|null
     * }
     */
    public static function summarize(
        Collection $phases,
        string $timezone,
        ?CarbonInterface $today = null,
    ): array {
        $today ??= now($timezone);
        $todayDate = self::localDate($today, $timezone);
        $categoryResolver = new CrewPhasePayCategoryResolver;
        $recorded = false;
        $countedADate = false;
        $hasFutureActual = false;

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

            $recorded = true;
            $from = self::localDate($phase->actual_start_at, $timezone);
            $to = self::localDate($end, $timezone);

            if ($from->gt($todayDate)) {
                $hasFutureActual = true;

                continue;
            }

            if ($to->gt($todayDate)) {
                $to = $todayDate;
            }

            $countedADate = true;
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

        if (! $recorded) {
            return [
                'recorded' => false,
                'future_actual_only' => false,
                'has_future_actual' => false,
                'vessel_service_days' => null,
                'standby_service_days' => null,
            ];
        }

        $vessel = 0;
        $standby = 0;

        foreach ($allocatedByDate as $allocation) {
            $vessel += $allocation['category'] === CrewTimesheetPayCategory::Onsite ? 1 : 0;
            $standby += in_array($allocation['category'], [
                CrewTimesheetPayCategory::SignOnStandby,
                CrewTimesheetPayCategory::SignOffStandby,
            ], true) ? 1 : 0;
        }

        return [
            'recorded' => true,
            'future_actual_only' => ! $countedADate,
            'has_future_actual' => $hasFutureActual,
            'vessel_service_days' => $vessel,
            'standby_service_days' => $standby,
        ];
    }

    private static function localDate(CarbonInterface $value, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $value->copy()->timezone($timezone)->toDateString(),
            $timezone,
        )->startOfDay();
    }
}
