<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Enums\CrewTimesheetPayCategory;
use App\Models\CrewTimesheetPreparationLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for "payable" preparation lines.
 *
 * A line is payable when it carries a payable pay category (sign-on standby,
 * onsite, or sign-off standby) and more than zero days. Excluded lines,
 * warning-only rows, and rows with days <= 0 never contribute to payroll and
 * must not require a linked Crew Operations timesheet.
 */
final class PayableCrewPreparationLines
{
    /**
     * @return list<CrewTimesheetPayCategory>
     */
    public static function payableCategories(): array
    {
        return [
            CrewTimesheetPayCategory::SignOnStandby,
            CrewTimesheetPayCategory::Onsite,
            CrewTimesheetPayCategory::SignOffStandby,
        ];
    }

    /**
     * @param  Builder<CrewTimesheetPreparationLine>  $query
     * @return Builder<CrewTimesheetPreparationLine>
     */
    public static function scopePayable(Builder $query): Builder
    {
        return $query
            ->whereIn('pay_category', array_map(
                fn (CrewTimesheetPayCategory $category): string => $category->value,
                self::payableCategories(),
            ))
            ->where('days', '>', 0);
    }

    public static function isPayable(CrewTimesheetPreparationLine $line): bool
    {
        return $line->pay_category !== null
            && in_array($line->pay_category, self::payableCategories(), true)
            && (float) $line->days > 0;
    }

    /**
     * @return list<int>
     */
    public static function payableEmployeeIds(int $companyId, int $preparationId): array
    {
        return self::scopePayable(
            CrewTimesheetPreparationLine::query()
                ->where('company_id', $companyId)
                ->where('crew_timesheet_preparation_id', $preparationId),
        )
            ->pluck('employee_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
