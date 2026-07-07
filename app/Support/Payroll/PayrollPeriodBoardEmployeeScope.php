<?php

namespace App\Support\Payroll;

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
}
