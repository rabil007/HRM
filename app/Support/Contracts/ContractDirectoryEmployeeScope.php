<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeVisibilityScope;
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
        ?User $user = null,
    ): void {
        $directoryFilters = new EmployeeDirectoryFilters(
            branchId: $filters->branchId,
            departmentId: $filters->departmentId,
        );

        $currentUser = $user ?? auth()->user();

        if ($currentUser instanceof User) {
            EmployeeVisibilityScope::apply($employeeQuery, $currentUser, $companyId);
        }

        EmployeeDirectoryQuery::applyAttributeFilters(
            $employeeQuery,
            $companyId,
            $directoryFilters,
            exceptDepartment: false,
            exceptPosition: true,
            user: $currentUser,
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
