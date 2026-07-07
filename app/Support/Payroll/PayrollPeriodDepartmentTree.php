<?php

namespace App\Support\Payroll;

use App\Models\PayrollPeriod;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;

final class PayrollPeriodDepartmentTree
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
        PayrollPeriod $period,
        EmployeeDirectoryFilters $directoryFilters,
        ?string $search,
        PayrollPeriodBoardFilters $boardFilters,
    ): array {
        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $directoryFilters,
            function (Builder $query) use ($companyId, $period, $search, $boardFilters): void {
                PayrollPeriodBoardEmployeeScope::apply(
                    $query,
                    $companyId,
                    $period,
                    $search,
                    $boardFilters,
                    exceptDepartmentFilters: true,
                );
            },
        );
    }
}
