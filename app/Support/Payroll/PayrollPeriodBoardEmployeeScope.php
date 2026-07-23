<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetBoardFilter;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollBoardEmployeeGroup;
use App\Enums\PayrollCategory;
use App\Enums\SalaryPaymentMethod;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Contracts\ContractSalaryStructureFilter;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Database\Eloquent\Builder;

final class PayrollPeriodBoardEmployeeScope
{
    /**
     * @param  Builder<Employee>  $query
     */
    public static function apply(
        Builder $query,
        int $companyId,
        PayrollPeriod $period,
        ?string $search,
        PayrollPeriodBoardFilters $filters,
        bool $exceptDepartmentFilters = false,
    ): void {
        $payrollCategory = $period->payroll_category ?? PayrollCategory::Crew;

        $query->whereIn(
            'employees.id',
            PayrollEmployeeQuery::activeQuery($companyId, $payrollCategory)->select('employees.id'),
        );

        self::applySearch($query, $search);

        if (! $exceptDepartmentFilters) {
            self::applyDepartmentFilters($query, $companyId, $filters);
        }

        self::applyEmployeeGroupFilter($query, $filters->employeeGroup);

        if ($payrollCategory === PayrollCategory::Crew) {
            self::applyCrewSalaryStructureFilter($query, $filters->crewSalaryStructure);
            self::applyCrewTimesheetFilter($query, $period, $filters->crewTimesheetFilter);
        }
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private static function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || trim($search) === '') {
            return;
        }

        $term = '%'.mb_strtolower(trim($search)).'%';

        $query->where(function (Builder $builder) use ($term): void {
            $builder
                ->whereRaw('LOWER(employees.employee_no) LIKE ?', [$term])
                ->orWhereRaw('LOWER(employees.name) LIKE ?', [$term]);
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private static function applyDepartmentFilters(
        Builder $query,
        int $companyId,
        PayrollPeriodBoardFilters $filters,
    ): void {
        if (! $filters->isActive()) {
            return;
        }

        EmployeeDirectoryQuery::applyAttributeFilters(
            $query,
            $companyId,
            new EmployeeDirectoryFilters(
                departmentId: $filters->departmentId,
                positionId: $filters->positionId,
                companyVisaTypeId: $filters->companyVisaTypeId,
            ),
            exceptDepartment: false,
            exceptPosition: false,
        );
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private static function applyEmployeeGroupFilter(
        Builder $query,
        PayrollBoardEmployeeGroup $employeeGroup,
    ): void {
        match ($employeeGroup) {
            PayrollBoardEmployeeGroup::WithBankAccount => $query->whereHas('primaryBankAccount'),
            PayrollBoardEmployeeGroup::CashPayment => $query->whereIn('salary_payment_method', [
                SalaryPaymentMethod::CashC3->value,
                SalaryPaymentMethod::CashAnsari->value,
                SalaryPaymentMethod::CashOther->value,
                SalaryPaymentMethod::ThirdParty->value,
            ]),
            PayrollBoardEmployeeGroup::MissingBankAccount => $query
                ->whereDoesntHave('primaryBankAccount')
                ->where(function (Builder $builder): void {
                    $builder
                        ->where('salary_payment_method', SalaryPaymentMethod::BankTransfer->value)
                        ->orWhereNull('salary_payment_method');
                }),
            PayrollBoardEmployeeGroup::Total => null,
        };
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private static function applyCrewSalaryStructureFilter(
        Builder $query,
        string $crewSalaryStructure,
    ): void {
        if (! ContractSalaryStructureFilter::isValid($crewSalaryStructure)) {
            return;
        }

        $query->whereHas('currentContract', function (Builder $contractQuery) use ($crewSalaryStructure): void {
            ContractSalaryStructureFilter::apply($contractQuery, $crewSalaryStructure);
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private static function applyCrewTimesheetFilter(
        Builder $query,
        PayrollPeriod $period,
        ?CrewTimesheetBoardFilter $filter,
    ): void {
        if ($filter === null) {
            return;
        }

        match ($filter) {
            CrewTimesheetBoardFilter::MissingTimesheet => $query
                ->whereDoesntHave(
                    'crewTimesheets',
                    fn (Builder $timesheetQuery) => $timesheetQuery->where('period_id', $period->id),
                )
                ->whereHas('currentContract', function (Builder $contractQuery): void {
                    $contractQuery->where(function (Builder $structureQuery): void {
                        $structureQuery
                            ->where('salary_structure', ContractSalaryStructure::Daily->value)
                            ->orWhereNull('salary_structure');
                    });
                }),
            CrewTimesheetBoardFilter::AwaitingApproval => $query->whereHas(
                'crewTimesheets',
                fn (Builder $timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->whereIn('source', [
                        CrewTimesheetSource::Manual->value,
                        CrewTimesheetSource::Import->value,
                    ])
                    ->whereIn('approval_status', [
                        CrewTimesheetApprovalStatus::Draft->value,
                        CrewTimesheetApprovalStatus::Submitted->value,
                        CrewTimesheetApprovalStatus::Returned->value,
                    ]),
            ),
            CrewTimesheetBoardFilter::Returned => $query->whereHas(
                'crewTimesheets',
                fn (Builder $timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->where('approval_status', CrewTimesheetApprovalStatus::Returned->value),
            ),
            CrewTimesheetBoardFilter::CrewOperations => $query->whereHas(
                'crewTimesheets',
                fn (Builder $timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->where('source', CrewTimesheetSource::CrewOperations->value),
            ),
            CrewTimesheetBoardFilter::Manual => $query->whereHas(
                'crewTimesheets',
                fn (Builder $timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->where('source', CrewTimesheetSource::Manual->value),
            ),
            CrewTimesheetBoardFilter::Import => $query->whereHas(
                'crewTimesheets',
                fn (Builder $timesheetQuery) => $timesheetQuery
                    ->where('period_id', $period->id)
                    ->where('source', CrewTimesheetSource::Import->value),
            ),
            CrewTimesheetBoardFilter::Ready => $query->where(function (Builder $readyQuery) use ($period): void {
                $readyQuery
                    ->whereHas(
                        'crewTimesheets',
                        fn (Builder $timesheetQuery) => $timesheetQuery
                            ->where('period_id', $period->id)
                            ->where(function (Builder $approvedQuery): void {
                                $approvedQuery
                                    ->where('source', CrewTimesheetSource::CrewOperations->value)
                                    ->orWhere('approval_status', CrewTimesheetApprovalStatus::Approved->value);
                            }),
                    )
                    ->orWhere(function (Builder $monthlyQuery) use ($period): void {
                        $monthlyQuery
                            ->whereHas('currentContract', fn (Builder $contractQuery) => $contractQuery
                                ->where('salary_structure', ContractSalaryStructure::Monthly->value))
                            ->where(function (Builder $monthlyTimesheetQuery) use ($period): void {
                                $monthlyTimesheetQuery
                                    ->whereDoesntHave(
                                        'crewTimesheets',
                                        fn (Builder $timesheetQuery) => $timesheetQuery->where('period_id', $period->id),
                                    )
                                    ->orWhereHas(
                                        'crewTimesheets',
                                        fn (Builder $timesheetQuery) => $timesheetQuery
                                            ->where('period_id', $period->id)
                                            ->where('approval_status', CrewTimesheetApprovalStatus::Approved->value),
                                    );
                            });
                    });
            }),
        };
    }
}
