<?php

namespace App\Support\SeaServices;

use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;

final class SeaServiceDepartmentTree
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
    public static function for(int $companyId, EmployeeDirectoryFilters $filters): array
    {
        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $filters,
            function (Builder $query) use ($companyId): void {
                $query->whereHas('seaServices', function (Builder $seaServiceQuery) use ($companyId): void {
                    $seaServiceQuery->where('company_id', $companyId);
                });
            },
        );
    }
}
