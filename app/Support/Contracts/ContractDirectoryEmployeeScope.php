<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Database\Eloquent\Builder;

final class ContractDirectoryEmployeeScope
{
    /**
     * @param  Builder<Employee>  $employeeQuery
     */
    public static function apply(
        Builder $employeeQuery,
        int $companyId,
        ContractDirectoryFilters $filters,
    ): void {
        $directoryFilters = new EmployeeDirectoryFilters(
            branchId: $filters->branchId,
            departmentId: $filters->departmentId,
        );

        EmployeeDirectoryQuery::applyAttributeFilters(
            $employeeQuery,
            $companyId,
            $directoryFilters,
            exceptDepartment: false,
            exceptPosition: true,
        );

        if ($filters->payrollCategory !== ''
            && ContractWorkforceDepartmentScope::isValid($filters->payrollCategory)) {
            ContractWorkforceDepartmentScope::apply(
                $employeeQuery,
                $companyId,
                $filters->payrollCategory,
            );
        }
    }
}
