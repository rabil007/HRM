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
        $treeFilters = new EmployeeDirectoryFilters(
            departmentId: $filters->departmentId,
            status: EmployeeDirectoryFilters::STATUS_ALL,
        );

        $tree = BuildDepartmentEmployeeTree::for(
            $companyId,
            $treeFilters,
            function (Builder $query) use ($companyId, $user): void {
                $query->whereExists(function ($subQuery) use ($companyId): void {
                    $subQuery->selectRaw('1')
                        ->from('leave_requests')
                        ->whereColumn('leave_requests.employee_id', 'employees.id')
                        ->where('leave_requests.company_id', $companyId)
                        ->whereNull('leave_requests.deleted_at');
                });

                EmployeeVisibilityScope::apply($query, $user, $companyId);
            },
            allowedDepartmentIds: EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId),
        );

        return self::pruneZeroCountDepartments($tree);
    }

    /**
     * @param  list<array{
     *     id: int|null,
     *     name: string,
     *     count: int,
     *     children: list<mixed>,
     *     positions: list<array{id: int, name: string, count: int}>
     * }>  $tree
     * @return list<array{
     *     id: int|null,
     *     name: string,
     *     count: int,
     *     children: list<mixed>,
     *     positions: list<array{id: int, name: string, count: int}>
     * }>
     */
    private static function pruneZeroCountDepartments(array $tree): array
    {
        $pruned = [];

        foreach ($tree as $node) {
            if ($node['id'] === null) {
                $pruned[] = $node;

                continue;
            }

            $node['children'] = self::pruneZeroCountDepartments($node['children'] ?? []);

            if ($node['count'] > 0 || $node['children'] !== []) {
                $pruned[] = $node;
            }
        }

        return $pruned;
    }
}
