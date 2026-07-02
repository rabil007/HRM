<?php

namespace App\Support\Employees;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BuildDepartmentEmployeeTree
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
    /**
     * @param  (callable(Builder<Employee>): void)|null  $employeeScope
     */
    public static function for(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?callable $employeeScope = null,
    ): array {
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        if ($departments->isEmpty()) {
            $total = self::countEmployees($companyId, $filters, $employeeScope);

            return [
                [
                    'id' => null,
                    'name' => 'All',
                    'count' => $total,
                    'children' => [],
                    'positions' => [],
                ],
            ];
        }

        $countsByDepartment = self::directCountsByDepartment($companyId, $filters, $employeeScope);
        $countsByDepartmentAndPosition = self::directCountsByDepartmentAndPosition($companyId, $filters, $employeeScope);
        $positionsByDepartment = Position::query()
            ->where('company_id', $companyId)
            ->orderBy('title')
            ->get(['id', 'department_id', 'title'])
            ->groupBy('department_id');

        $departmentIds = $departments->pluck('id')->all();
        $departmentIdSet = array_fill_keys($departmentIds, true);

        $childrenByParent = [];

        foreach ($departments as $department) {
            $parentId = $department->parent_id;

            if ($parentId !== null && isset($departmentIdSet[$parentId])) {
                $childrenByParent[$parentId][] = $department->id;
            }
        }

        $subtreeCounts = [];

        $computeSubtreeCount = function (int $departmentId) use (
            &$computeSubtreeCount,
            &$subtreeCounts,
            $childrenByParent,
            $countsByDepartment,
        ): int {
            if (array_key_exists($departmentId, $subtreeCounts)) {
                return $subtreeCounts[$departmentId];
            }

            $total = $countsByDepartment[$departmentId] ?? 0;

            foreach ($childrenByParent[$departmentId] ?? [] as $childId) {
                $total += $computeSubtreeCount($childId);
            }

            $subtreeCounts[$departmentId] = $total;

            return $total;
        };

        $buildNode = function (int $departmentId) use (
            &$buildNode,
            $departments,
            $childrenByParent,
            &$computeSubtreeCount,
            $positionsByDepartment,
            $countsByDepartmentAndPosition,
        ): array {
            $department = $departments->firstWhere('id', $departmentId);

            $childIds = $childrenByParent[$departmentId] ?? [];
            usort($childIds, function (int $a, int $b) use ($departments): int {
                $nameA = (string) ($departments->firstWhere('id', $a)?->name ?? '');
                $nameB = (string) ($departments->firstWhere('id', $b)?->name ?? '');

                return strcasecmp($nameA, $nameB);
            });

            $children = array_map(
                fn (int $childId): array => $buildNode($childId),
                $childIds,
            );

            $positions = ($positionsByDepartment->get($departmentId) ?? collect())
                ->map(function (Position $position) use ($departmentId, $countsByDepartmentAndPosition): array {
                    return [
                        'id' => $position->id,
                        'name' => (string) $position->title,
                        'count' => $countsByDepartmentAndPosition[$departmentId][$position->id] ?? 0,
                    ];
                })
                ->values()
                ->all();

            return [
                'id' => $departmentId,
                'name' => (string) ($department?->name ?? ''),
                'count' => $computeSubtreeCount($departmentId),
                'children' => $children,
                'positions' => $positions,
            ];
        };

        $rootIds = $departments
            ->filter(function (Department $department) use ($departmentIdSet): bool {
                $parentId = $department->parent_id;

                return $parentId === null || ! isset($departmentIdSet[$parentId]);
            })
            ->pluck('id')
            ->all();

        usort($rootIds, function (int $a, int $b) use ($departments): int {
            $nameA = (string) ($departments->firstWhere('id', $a)?->name ?? '');
            $nameB = (string) ($departments->firstWhere('id', $b)?->name ?? '');

            return strcasecmp($nameA, $nameB);
        });

        $treeRoots = array_map(
            fn (int $rootId): array => $buildNode($rootId),
            $rootIds,
        );

        return [
            [
                'id' => null,
                'name' => 'All',
                'count' => self::countEmployees($companyId, $filters, $employeeScope),
                'children' => [],
                'positions' => [],
            ],
            ...$treeRoots,
        ];
    }

    /**
     * @return array<int, int>
     */
    /**
     * @param  (callable(Builder<Employee>): void)|null  $employeeScope
     */
    private static function directCountsByDepartment(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?callable $employeeScope = null,
    ): array {
        $query = Employee::query()
            ->where('company_id', $companyId)
            ->whereNotNull('department_id');

        self::applyTreeCountFilters($query, $companyId, $filters, $employeeScope);

        $rows = $query
            ->select('department_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->department_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @return array<int, array<int, int>>
     */
    /**
     * @param  (callable(Builder<Employee>): void)|null  $employeeScope
     */
    private static function directCountsByDepartmentAndPosition(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?callable $employeeScope = null,
    ): array {
        $query = Employee::query()
            ->where('company_id', $companyId)
            ->whereNotNull('department_id')
            ->whereNotNull('position_id');

        self::applyTreeCountFilters($query, $companyId, $filters, $employeeScope);

        $rows = $query
            ->select('department_id', 'position_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id', 'position_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $departmentId = (int) $row->department_id;
            $positionId = (int) $row->position_id;
            $counts[$departmentId][$positionId] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * @param  (callable(Builder<Employee>): void)|null  $employeeScope
     */
    private static function countEmployees(
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?callable $employeeScope = null,
    ): int {
        $query = Employee::query()->where('company_id', $companyId);

        self::applyTreeCountFilters($query, $companyId, $filters, $employeeScope);

        return $query->count();
    }

    /**
     * @param  Builder<Employee>  $query
     * @param  (callable(Builder<Employee>): void)|null  $employeeScope
     */
    private static function applyTreeCountFilters(
        Builder $query,
        int $companyId,
        EmployeeDirectoryFilters $filters,
        ?callable $employeeScope = null,
    ): void {
        EmployeeDirectoryQuery::applyAttributeFilters(
            $query,
            $companyId,
            $filters,
            exceptDepartment: true,
            exceptPosition: true,
        );

        if ($employeeScope !== null) {
            $employeeScope($query);
        }
    }
}
