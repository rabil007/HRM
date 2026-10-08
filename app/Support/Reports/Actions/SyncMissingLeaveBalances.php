<?php

namespace App\Support\Reports\Actions;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Reports\SyncMissingLeaveBalancesResult;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Collection;

final class SyncMissingLeaveBalances
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
    ) {}

    public function handle(int $companyId, User $actor): SyncMissingLeaveBalancesResult
    {
        $year = (int) now(CompanyTimezone::forCompanyId($companyId))->year;

        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'company_id', 'days_per_year', 'status', 'category']);

        $activeLeaveTypesCount = $leaveTypes->count();
        $eligibleEmployeesChecked = 0;
        $employeesWithNewBalances = 0;
        $newBalanceRecordsCreated = 0;
        $alreadyExistingBalances = 0;
        $skippedOrAnomalies = 0;

        if ($activeLeaveTypesCount === 0) {
            $result = new SyncMissingLeaveBalancesResult(
                eligibleEmployeesChecked: 0,
                employeesWithNewBalances: 0,
                newBalanceRecordsCreated: 0,
                alreadyExistingBalances: 0,
                skippedOrAnomalies: 0,
                activeLeaveTypesCount: 0,
                year: $year,
            );

            $this->logActivity($companyId, $actor, $result);

            return $result;
        }

        Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->tap(fn ($query) => AttendanceLeaveDepartmentScope::apply($query, $companyId))
            ->tap(fn ($query) => EmployeeVisibilityScope::apply($query, $actor, $companyId))
            ->orderBy('id')
            ->chunkById(100, function (Collection $employees) use (
                $companyId,
                $actor,
                $leaveTypes,
                $year,
                &$eligibleEmployeesChecked,
                &$employeesWithNewBalances,
                &$newBalanceRecordsCreated,
                &$alreadyExistingBalances,
                &$skippedOrAnomalies,
            ): void {
                foreach ($employees as $employee) {
                    $employee->refresh();

                    if (! $this->employeeIsEligible($employee, $companyId, $actor)) {
                        continue;
                    }

                    $eligibleEmployeesChecked++;
                    $createdForEmployee = 0;

                    foreach ($leaveTypes as $leaveType) {
                        $outcome = $this->leaveBalances->provisionMissingBalanceForYear(
                            $companyId,
                            (int) $employee->id,
                            $leaveType,
                            $year,
                        );

                        if ($outcome === 'created') {
                            $createdForEmployee++;
                            $newBalanceRecordsCreated++;
                        } elseif ($outcome === 'existing') {
                            $alreadyExistingBalances++;
                        } elseif ($outcome === 'skipped') {
                            // Expected skip (e.g. annual leave without hire date).
                        } else {
                            $skippedOrAnomalies++;
                        }
                    }

                    if ($createdForEmployee > 0) {
                        $employeesWithNewBalances++;
                    }
                }
            });

        $result = new SyncMissingLeaveBalancesResult(
            eligibleEmployeesChecked: $eligibleEmployeesChecked,
            employeesWithNewBalances: $employeesWithNewBalances,
            newBalanceRecordsCreated: $newBalanceRecordsCreated,
            alreadyExistingBalances: $alreadyExistingBalances,
            skippedOrAnomalies: $skippedOrAnomalies,
            activeLeaveTypesCount: $activeLeaveTypesCount,
            year: $year,
        );

        $this->logActivity($companyId, $actor, $result);

        return $result;
    }

    private function employeeIsEligible(Employee $employee, int $companyId, User $actor): bool
    {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ((string) $employee->status !== 'active') {
            return false;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $companyId)) {
            return false;
        }

        return EmployeeVisibilityScope::canAccess($actor, $employee, $companyId);
    }

    private function logActivity(int $companyId, User $actor, SyncMissingLeaveBalancesResult $result): void
    {
        $company = Company::query()->whereKey($companyId)->first();

        if ($company === null) {
            return;
        }

        $activity = activity()
            ->performedOn($company)
            ->causedBy($actor)
            ->withProperties([
                'event' => 'leave_balance_report.missing_balances_synced',
                'company_id' => $companyId,
                'year' => $result->year,
                'eligible_employees_checked' => $result->eligibleEmployeesChecked,
                'employees_with_new_balances' => $result->employeesWithNewBalances,
                'new_balance_records_created' => $result->newBalanceRecordsCreated,
                'already_existing_balances' => $result->alreadyExistingBalances,
                'skipped_or_anomalies' => $result->skippedOrAnomalies,
                'active_leave_types_count' => $result->activeLeaveTypesCount,
                'message_variant' => $result->messageVariant(),
            ])
            ->log('Synced missing leave balances for current business year');

        $activity->forceFill(['company_id' => $companyId])->save();
    }
}
