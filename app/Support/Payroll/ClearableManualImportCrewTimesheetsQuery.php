<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetSource;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-scoped query for Manual / Excel Import timesheets that may be cleared
 * from a Draft crew payroll period. Legacy null sources resolve as Manual.
 */
final class ClearableManualImportCrewTimesheetsQuery
{
    /**
     * @return Builder<CrewTimesheet>
     */
    public function forPeriod(PayrollPeriod $period, int $companyId): Builder
    {
        return CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->where('period_id', $period->id)
            ->where(function (Builder $query): void {
                $query
                    ->whereIn('source', [
                        CrewTimesheetSource::Manual->value,
                        CrewTimesheetSource::Import->value,
                    ])
                    ->orWhereNull('source');
            })
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('source')
                    ->orWhere('source', '!=', CrewTimesheetSource::CrewOperations->value);
            })
            ->whereNull('crew_timesheet_preparation_id');
    }

    public function count(PayrollPeriod $period, int $companyId): int
    {
        if ((int) $period->company_id !== $companyId || ! $period->isCrew()) {
            return 0;
        }

        return $this->forPeriod($period, $companyId)->count();
    }

    public function isClearable(CrewTimesheet $timesheet): bool
    {
        if ($timesheet->resolvedSource() === CrewTimesheetSource::CrewOperations) {
            return false;
        }

        if ($timesheet->crew_timesheet_preparation_id !== null) {
            return false;
        }

        if ($timesheet->isOperationallyLocked()) {
            return false;
        }

        return in_array(
            $timesheet->resolvedSource(),
            [CrewTimesheetSource::Manual, CrewTimesheetSource::Import],
            true,
        );
    }
}
