<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class SyncCrewTimesheetParentFromSegments
{
    /**
     * Recalculate parent operational category totals from active segments.
     *
     * Only calendar days that fall inside the payroll period count toward parent
     * present-day totals. Prior-period arrears days on the same segments are excluded.
     *
     * Multiple current-period portions in the same category leave parent from/to null
     * so the UI can show "Multiple periods" instead of a misleading continuous range.
     */
    public function handle(CrewTimesheet $timesheet, ?PayrollPeriod $period = null): CrewTimesheet
    {
        $period ??= $timesheet->relationLoaded('period')
            ? $timesheet->period
            : $timesheet->period()->first();

        $segments = $timesheet->relationLoaded('segments')
            ? $timesheet->segments
            : $timesheet->segments()->get();

        $payload = $this->operationalPayloadFromSegments($segments, $period);
        $timesheet->fill($payload);
        $timesheet->save();

        return $timesheet->fresh(['segments']) ?? $timesheet;
    }

    public function hasSegments(CrewTimesheet $timesheet): bool
    {
        if ($timesheet->relationLoaded('segments')) {
            return $timesheet->segments->isNotEmpty();
        }

        return $timesheet->segments()->exists();
    }

    /**
     * @param  Collection<int, CrewTimesheetSegment>|iterable<int, CrewTimesheetSegment>  $segments
     * @return array{
     *     sign_on_standby_from: string|null,
     *     sign_on_standby_to: string|null,
     *     sign_on_standby_days: float,
     *     onsite_from: string|null,
     *     onsite_to: string|null,
     *     onsite_days: float,
     *     sign_off_standby_from: string|null,
     *     sign_off_standby_to: string|null,
     *     sign_off_standby_days: float
     * }
     */
    public function operationalPayloadFromSegments(iterable $segments, ?PayrollPeriod $period = null): array
    {
        $byCategory = [
            CrewTimesheetPayCategory::SignOnStandby->value => [],
            CrewTimesheetPayCategory::Onsite->value => [],
            CrewTimesheetPayCategory::SignOffStandby->value => [],
        ];

        foreach ($segments as $segment) {
            $category = $segment->pay_category instanceof CrewTimesheetPayCategory
                ? $segment->pay_category->value
                : (string) $segment->pay_category;

            if (! array_key_exists($category, $byCategory)) {
                continue;
            }

            $portion = $this->currentPeriodPortion($segment, $period);

            if ($portion === null) {
                continue;
            }

            $byCategory[$category][] = $portion;
        }

        return [
            ...$this->categoryFields('sign_on_standby', $byCategory[CrewTimesheetPayCategory::SignOnStandby->value]),
            ...$this->categoryFields('onsite', $byCategory[CrewTimesheetPayCategory::Onsite->value]),
            ...$this->categoryFields('sign_off_standby', $byCategory[CrewTimesheetPayCategory::SignOffStandby->value]),
        ];
    }

    /**
     * @return array{from_date: string, to_date: string, days: float}|null
     */
    private function currentPeriodPortion(CrewTimesheetSegment $segment, ?PayrollPeriod $period): ?array
    {
        if ($segment->from_date === null || $segment->to_date === null) {
            return null;
        }

        $from = CarbonImmutable::parse($segment->from_date)->startOfDay();
        $to = CarbonImmutable::parse($segment->to_date)->startOfDay();

        if ($period?->start_date !== null && $period->end_date !== null) {
            $periodStart = CarbonImmutable::parse($period->start_date)->startOfDay();
            $periodEnd = CarbonImmutable::parse($period->end_date)->startOfDay();

            $clippedFrom = $from->greaterThan($periodStart) ? $from : $periodStart;
            $clippedTo = $to->lessThan($periodEnd) ? $to : $periodEnd;

            if ($clippedFrom->greaterThan($clippedTo)) {
                return null;
            }

            $from = $clippedFrom;
            $to = $clippedTo;
        }

        return [
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'days' => (float) ($from->diffInDays($to) + 1),
        ];
    }

    /**
     * @param  list<array{from_date: string, to_date: string, days: float}>  $portions
     * @return array<string, float|string|null>
     */
    private function categoryFields(string $prefix, array $portions): array
    {
        $days = round(array_sum(array_map(
            static fn (array $portion): float => (float) $portion['days'],
            $portions,
        )), 2);

        if (count($portions) === 1) {
            $only = $portions[0];

            return [
                "{$prefix}_from" => $only['from_date'],
                "{$prefix}_to" => $only['to_date'],
                "{$prefix}_days" => $days,
            ];
        }

        return [
            "{$prefix}_from" => null,
            "{$prefix}_to" => null,
            "{$prefix}_days" => $days,
        ];
    }
}
