<?php

namespace App\Support\Employees;

use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class BuildDepartmentEmployeeTree
{
    /**
     * @return list<array{id: int|null, name: string, count: int, children: list<mixed>}>
     */
    public static function for(int $companyId, EmployeeDirectoryFilters $filters): array
    {
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        if ($departments->isEmpty()) {
            $total = self::countEmployees($companyId, $filters);

            return [
                [
                    'id' => null,
                    'name' => 'All',
                    'count' => $total,
                    'children' => [],
                ],
            ];
        }

        $countsByDepartment = self::directCountsByDepartment($companyId, $filters);
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

            return [
                'id' => $departmentId,
                'name' => (string) ($department?->name ?? ''),
                'count' => $computeSubtreeCount($departmentId),
                'children' => $children,
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
                'count' => self::countEmployees($companyId, $filters),
                'children' => [],
            ],
            ...$treeRoots,
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function directCountsByDepartment(int $companyId, EmployeeDirectoryFilters $filters): array
    {
        $query = Employee::query()
            ->where('company_id', $companyId)
            ->whereNotNull('department_id');

        self::applyFiltersExceptDepartment($query, $filters);

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

    private static function countEmployees(int $companyId, EmployeeDirectoryFilters $filters): int
    {
        $query = Employee::query()->where('company_id', $companyId);

        self::applyFiltersExceptDepartment($query, $filters);

        return $query->count();
    }

    private static function applyFiltersExceptDepartment(
        Builder $query,
        EmployeeDirectoryFilters $filters,
    ): void {
        $query
            ->when($filters->branchId, fn ($q) => $q->where('branch_id', $filters->branchId))
            ->when($filters->positionId, fn ($q) => $q->where('position_id', $filters->positionId))
            ->when($filters->status, fn ($q) => $q->where('status', $filters->status))
            ->when($filters->search, function ($q) use ($filters): void {
                $search = $filters->search;

                $q->where(function ($inner) use ($search): void {
                    $inner->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('work_email', 'like', "%{$search}%")
                        ->orWhere('personal_email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            });
    }
}
