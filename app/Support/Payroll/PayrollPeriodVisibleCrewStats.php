<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Contracts\ContractSalaryStructureFilter;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Actor-scoped Crew timesheet / payroll-record counts for Payroll hub lists.
 *
 * @phpstan-type PeriodStats array{
 *     filled_daily_timesheets: int,
 *     visible_crew_timesheets: int,
 *     daily_payroll_records: int,
 *     daily_payroll_records_with_timesheet: int
 * }
 */
final class PayrollPeriodVisibleCrewStats
{
    /**
     * @return array{eligible_daily_crew: int, visible_crew_employees: int}
     */
    public static function companyBaseline(int $companyId, ?User $user): array
    {
        $dailyCrewQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew)
            ->whereHas('currentContract', function (Builder $contractQuery): void {
                ContractSalaryStructureFilter::apply(
                    $contractQuery,
                    ContractSalaryStructureFilter::DAILY,
                );
            });

        $crewEmployeesQuery = PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        if ($user !== null) {
            EmployeeVisibilityScope::apply($dailyCrewQuery, $user, $companyId);
            EmployeeVisibilityScope::apply($crewEmployeesQuery, $user, $companyId);
        }

        return [
            'eligible_daily_crew' => $dailyCrewQuery->count(),
            'visible_crew_employees' => $crewEmployeesQuery->count(),
        ];
    }

    /**
     * @param  list<int>|Collection<int, int>  $periodIds
     * @return array<int, PeriodStats>
     */
    public static function forPeriods(iterable $periodIds, int $companyId, ?User $user): array
    {
        $ids = array_values(array_unique(array_map(intval(...), is_array($periodIds) ? $periodIds : iterator_to_array($periodIds))));

        $empty = [
            'filled_daily_timesheets' => 0,
            'visible_crew_timesheets' => 0,
            'daily_payroll_records' => 0,
            'daily_payroll_records_with_timesheet' => 0,
        ];

        if ($ids === []) {
            return [];
        }

        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = $empty;
        }

        $dailyTimesheetsQuery = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->whereIn('period_id', $ids)
            ->whereHas('employee.currentContract', function (Builder $contractQuery): void {
                $contractQuery->where('payroll_category', PayrollCategory::Crew->value);
                ContractSalaryStructureFilter::apply(
                    $contractQuery,
                    ContractSalaryStructureFilter::DAILY,
                );
            });

        EmployeeVisibilityScope::whereHas($dailyTimesheetsQuery, $user, $companyId, 'employee');

        foreach ($dailyTimesheetsQuery->selectRaw('period_id, count(*) as aggregate')->groupBy('period_id')->get() as $row) {
            $stats[(int) $row->period_id]['filled_daily_timesheets'] = (int) $row->aggregate;
        }

        $allTimesheetsQuery = CrewTimesheet::query()
            ->where('company_id', $companyId)
            ->whereIn('period_id', $ids);

        EmployeeVisibilityScope::whereHas($allTimesheetsQuery, $user, $companyId, 'employee');

        foreach ($allTimesheetsQuery->selectRaw('period_id, count(*) as aggregate')->groupBy('period_id')->get() as $row) {
            $stats[(int) $row->period_id]['visible_crew_timesheets'] = (int) $row->aggregate;
        }

        $dailyRecordsQuery = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('period_id', $ids)
            ->crewDaily();

        PayrollRecordAccess::apply($dailyRecordsQuery, $user, $companyId);

        foreach ($dailyRecordsQuery->selectRaw('period_id, count(*) as aggregate')->groupBy('period_id')->get() as $row) {
            $stats[(int) $row->period_id]['daily_payroll_records'] = (int) $row->aggregate;
        }

        $dailyRecordsWithTimesheetQuery = PayrollRecord::query()
            ->where('company_id', $companyId)
            ->whereIn('period_id', $ids)
            ->crewDaily()
            ->whereHas('employee.crewTimesheets', function (Builder $timesheetQuery): void {
                $timesheetQuery->whereColumn('crew_timesheets.period_id', 'payroll_records.period_id');
            });

        PayrollRecordAccess::apply($dailyRecordsWithTimesheetQuery, $user, $companyId);

        foreach ($dailyRecordsWithTimesheetQuery->selectRaw('period_id, count(*) as aggregate')->groupBy('period_id')->get() as $row) {
            $stats[(int) $row->period_id]['daily_payroll_records_with_timesheet'] = (int) $row->aggregate;
        }

        return $stats;
    }

    /**
     * @param  PeriodStats  $periodStats
     * @return array{0: int, 1: int}
     */
    public static function progressCounts(array $periodStats, int $eligibleDailyCrewCount): array
    {
        $dailyPayrollRecords = (int) ($periodStats['daily_payroll_records'] ?? 0);

        if ($dailyPayrollRecords > 0) {
            return [
                $dailyPayrollRecords,
                (int) ($periodStats['daily_payroll_records_with_timesheet'] ?? 0),
            ];
        }

        return [
            $eligibleDailyCrewCount,
            (int) ($periodStats['filled_daily_timesheets'] ?? 0),
        ];
    }

    public static function forPeriod(PayrollPeriod $period, int $companyId, ?User $user): array
    {
        $baseline = self::companyBaseline($companyId, $user);
        $periodStats = self::forPeriods([(int) $period->id], $companyId, $user)[(int) $period->id]
            ?? [
                'filled_daily_timesheets' => 0,
                'visible_crew_timesheets' => 0,
                'daily_payroll_records' => 0,
                'daily_payroll_records_with_timesheet' => 0,
            ];

        [$eligible, $filled] = self::progressCounts($periodStats, $baseline['eligible_daily_crew']);

        return [
            'eligible_daily_crew_count' => $eligible,
            'filled_daily_timesheet_count' => $filled,
            'daily_payroll_record_count' => (int) $periodStats['daily_payroll_records'],
            'daily_payroll_records_with_timesheet_count' => (int) $periodStats['daily_payroll_records_with_timesheet'],
            'visible_crew_timesheet_count' => (int) $periodStats['visible_crew_timesheets'],
            'visible_crew_employee_count' => $baseline['visible_crew_employees'],
        ];
    }
}
