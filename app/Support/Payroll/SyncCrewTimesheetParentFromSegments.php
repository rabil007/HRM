<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetSegment;
use Illuminate\Support\Collection;

final class SyncCrewTimesheetParentFromSegments
{
    /**
     * Recalculate parent operational category totals from active segments.
     *
     * Multiple segments in the same category leave parent from/to null so the
     * UI can show "Multiple periods" instead of a misleading continuous range.
     */
    public function handle(CrewTimesheet $timesheet): CrewTimesheet
    {
        $segments = $timesheet->relationLoaded('segments')
            ? $timesheet->segments
            : $timesheet->segments()->get();

        $payload = $this->operationalPayloadFromSegments($segments);
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
    public function operationalPayloadFromSegments(iterable $segments): array
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

            $byCategory[$category][] = $segment;
        }

        return [
            ...$this->categoryFields('sign_on_standby', $byCategory[CrewTimesheetPayCategory::SignOnStandby->value]),
            ...$this->categoryFields('onsite', $byCategory[CrewTimesheetPayCategory::Onsite->value]),
            ...$this->categoryFields('sign_off_standby', $byCategory[CrewTimesheetPayCategory::SignOffStandby->value]),
        ];
    }

    /**
     * @param  list<CrewTimesheetSegment>  $segments
     * @return array<string, float|string|null>
     */
    private function categoryFields(string $prefix, array $segments): array
    {
        $days = round(array_sum(array_map(
            static fn (CrewTimesheetSegment $segment): float => (float) $segment->days,
            $segments,
        )), 2);

        if (count($segments) === 1) {
            $only = $segments[0];

            return [
                "{$prefix}_from" => $only->from_date?->toDateString(),
                "{$prefix}_to" => $only->to_date?->toDateString(),
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
