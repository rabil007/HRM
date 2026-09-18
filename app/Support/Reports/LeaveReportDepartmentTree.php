<?php

namespace App\Support\Reports;

use App\Models\User;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

final class LeaveReportDepartmentTree
{
    /**
     * @return list<array{
     *     id: int|null,
     *     name: string,
     *     count: int,
     *     children: list<mixed>,
     *     positions: list<array{id: int, name: string, count: int}>
     * }>
     */
    public static function for(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        User $user,
    ): array {
        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $filters,
            function (Builder $query) use ($companyId, $user): void {
                $query->whereExists(function ($subQuery) use ($companyId): void {
                    $subQuery->selectRaw('1')
                        ->from('leave_requests')
                        ->whereColumn('leave_requests.employee_id', 'employees.id')
                        ->where('leave_requests.company_id', $companyId);
                });

                EmployeeVisibilityScope::apply($query, $user, $companyId);
            },
        );
    }
}
