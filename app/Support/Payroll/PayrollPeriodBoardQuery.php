<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class PayrollPeriodBoardQuery
{
    public function __construct(
        private readonly OfficeLeavePeriodSummary $leavePeriodSummary,
        private readonly ResolveCrewContractForPayrollPeriod $resolveCrewContract,
        private readonly ResolveOfficeContractForPayrollPeriod $resolveOfficeContract,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(
        int $companyId,
        PayrollPeriod $period,
        ?string $search = null,
        int $perPage = 25,
        ?PayrollPeriodBoardFilters $filters = null,
    ): LengthAwarePaginator {
        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;
        $filters ??= new PayrollPeriodBoardFilters;

        $query = $payrollCategory === PayrollCategory::Office
            ? PayrollEmployeeQuery::forPeriod($period, PayrollCategory::Office)
            : PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        $query->with([
            'department.parent:id,name',
            'position:id,title',
        ]);

        if ($payrollCategory === PayrollCategory::Crew) {
            $query->with([
                'primaryBankAccount.bank:id,name',
                'crewTimesheets' => fn ($timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->with(['segments.assignment', 'segments.vessel', 'segments.client', 'segments.rank']),
            ]);
        }

        if ($payrollCategory === PayrollCategory::Office) {
            $query->with([
                'primaryBankAccount.bank:id,name',
            ]);
        }

        PayrollPeriodBoardEmployeeScope::apply($query, $companyId, $period, $search, $filters);

        $paginator = $query
            ->orderBy('employees.name')
            ->paginate($perPage)
            ->withQueryString();

        $employeeIds = $paginator->getCollection()->pluck('id')->map(intval(...))->all();
        $resolvedContracts = $payrollCategory === PayrollCategory::Crew
            ? $this->resolveCrewContract->resolveMany(
                $period,
                $employeeIds,
                ['salaryComponents', 'salaryRevisionHistory.lines'],
            )
            : $this->resolveOfficeContract->resolveMany(
                $period,
                $employeeIds,
                ['salaryComponents', 'salaryRevisionHistory.lines'],
            );
        $ambiguousCrewEmployeeIds = $payrollCategory === PayrollCategory::Crew
            ? $this->resolveCrewContract->ambiguousEmployeeIds($period, $employeeIds)
            : [];

        $leaveByEmployee = $payrollCategory === PayrollCategory::Office
            ? $this->leavePeriodSummary->forEmployees(
                $companyId,
                $period->start_date->toDateString(),
                $period->end_date->toDateString(),
                $paginator->getCollection()->pluck('id')->map(intval(...))->all(),
            )
            : Collection::make();
        $emptyLeaveSummary = $payrollCategory === PayrollCategory::Office
            ? $this->leavePeriodSummary->empty($companyId)
            : null;

        return $paginator->through(function (Employee $employee) use (
            $period,
            $payrollCategory,
            $leaveByEmployee,
            $emptyLeaveSummary,
            $resolvedContracts,
            $ambiguousCrewEmployeeIds,
        ) {
            $contractIssue = null;

            if ($payrollCategory === PayrollCategory::Crew) {
                $contract = $resolvedContracts->get((int) $employee->id);

                if (in_array((int) $employee->id, $ambiguousCrewEmployeeIds, true)) {
                    $contract = null;
                    $contractIssue = [
                        'code' => 'overlapping_historical_contracts',
                        'message' => 'Multiple Crew contracts overlap this payroll period.',
                    ];
                }
            } else {
                $contractResult = $resolvedContracts->get((int) $employee->id);
                $contract = $contractResult['contract'] ?? null;
                $contractIssue = $contractResult['issue'] ?? null;
            }

            $employee->setRelation('currentContract', $contract);

            if ($payrollCategory === PayrollCategory::Crew) {
                /** @var CrewTimesheet|null $timesheet */
                $timesheet = $employee->crewTimesheets->first();

                $row = CrewTimesheetResource::toBoardRow(
                    $employee,
                    $timesheet,
                    $period->id,
                    $period->start_date,
                );

                $row['contract_resolution_issue'] = $contractIssue;

                return $row;
            }

            $summary = $leaveByEmployee->get(
                $employee->id,
                $emptyLeaveSummary,
            );

            $row = OfficePayrollBoardRow::toArray(
                $employee,
                $period->id,
                $summary,
                $period->start_date,
            );

            $row['contract_resolution_issue'] = $contractIssue;

            return $row;
        });
    }

    /**
     * @return list<int>
     */
    public function allEmployeeIds(
        int $companyId,
        PayrollPeriod $period,
        ?string $search = null,
        ?PayrollPeriodBoardFilters $filters = null,
    ): array {
        $filters ??= new PayrollPeriodBoardFilters;

        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;
        $query = $payrollCategory === PayrollCategory::Office
            ? PayrollEmployeeQuery::forPeriod($period, PayrollCategory::Office)
            : PayrollEmployeeQuery::activeQuery($companyId, PayrollCategory::Crew);

        PayrollPeriodBoardEmployeeScope::apply($query, $companyId, $period, $search, $filters);

        return $query
            ->orderBy('employees.name')
            ->pluck('employees.id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
