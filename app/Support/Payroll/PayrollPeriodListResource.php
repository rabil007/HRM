<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\PayrollPeriod;
use App\Support\Contracts\ContractSalaryStructureFilter;
use Illuminate\Database\Eloquent\Builder;

final class PayrollPeriodListResource
{
    /**
     * @param  array{crew: int, office: int, daily_crew: int}  $employeeCountsByCategory
     * @return array<string, mixed>
     */
    public static function toArray(PayrollPeriod $period, array $employeeCountsByCategory): array
    {
        $category = $period->payroll_category ?? PayrollCategory::Crew;
        $employeeCount = $employeeCountsByCategory[$category->value] ?? 0;

        [$timesheetEligibleCount, $filledCount] = $category === PayrollCategory::Crew
            ? self::crewTimesheetProgress($period, $employeeCountsByCategory['daily_crew'] ?? 0)
            : [0, 0];

        return [
            ...PayrollPeriodResource::toArray($period),
            'run_label' => $period->name.' · '.$category->label(),
            'employee_count' => $employeeCount,
            'timesheet_eligible_count' => $timesheetEligibleCount,
            'timesheets_filled_count' => $filledCount,
            'timesheets_progress_label' => $category === PayrollCategory::Crew
                ? ($timesheetEligibleCount > 0
                    ? "{$filledCount}/{$timesheetEligibleCount}"
                    : '0/0')
                : null,
            'supports_timesheets' => $category === PayrollCategory::Crew,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function crewTimesheetProgress(PayrollPeriod $period, int $companyDailyCrewCount): array
    {
        $dailyPayrollRecordsCount = (int) ($period->daily_payroll_records_count ?? 0);

        if ($dailyPayrollRecordsCount > 0) {
            $filledCount = (int) ($period->daily_payroll_records_with_timesheet_count
                ?? self::dailyPayrollRecordsWithTimesheetCount($period));

            return [$dailyPayrollRecordsCount, $filledCount];
        }

        $filledCount = (int) ($period->daily_crew_timesheets_count
            ?? $period->crew_timesheets_count
            ?? self::dailyCrewTimesheetCount($period));

        return [$companyDailyCrewCount, $filledCount];
    }

    private static function dailyCrewTimesheetCount(PayrollPeriod $period): int
    {
        return $period->crewTimesheets()
            ->whereHas('employee.currentContract', function (Builder $contractQuery): void {
                $contractQuery->where('payroll_category', PayrollCategory::Crew->value);
                ContractSalaryStructureFilter::apply(
                    $contractQuery,
                    ContractSalaryStructureFilter::DAILY,
                );
            })
            ->count();
    }

    private static function dailyPayrollRecordsWithTimesheetCount(PayrollPeriod $period): int
    {
        return $period->payrollRecords()
            ->crewDaily()
            ->whereHas('employee.crewTimesheets', function (Builder $timesheetQuery) use ($period): void {
                $timesheetQuery->where('crew_timesheets.period_id', $period->id);
            })
            ->count();
    }
}
