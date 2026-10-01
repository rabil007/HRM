<?php

namespace App\Support\Positions;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Support\Facades\DB;

final class PositionDepartmentTree
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
    public static function for(int $companyId): array
    {
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        $total = Position::query()
            ->where('company_id', $companyId)
            ->count();

        $allNode = [
            'id' => null,
            'name' => 'All',
            'count' => $total,
            'children' => [],
            'positions' => [],
        ];

        if ($departments->isEmpty()) {
            return [$allNode];
        }

        $countsByDepartment = self::directCountsByDepartment($companyId);
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

            $totalForDepartment = $countsByDepartment[$departmentId] ?? 0;

            foreach ($childrenByParent[$departmentId] ?? [] as $childId) {
                $totalForDepartment += $computeSubtreeCount($childId);
            }

            $subtreeCounts[$departmentId] = $totalForDepartment;

            return $totalForDepartment;
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

            return [
                'id' => $departmentId,
                'name' => (string) ($department?->name ?? ''),
                'count' => $computeSubtreeCount($departmentId),
                'children' => array_map(
                    fn (int $childId): array => $buildNode($childId),
                    $childIds,
                ),
                'positions' => [],
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

        return [
            $allNode,
            ...array_map(
                fn (int $rootId): array => $buildNode($rootId),
                $rootIds,
            ),
        ];
    }

    /**
     * @return array<int, int>
     */
    private static function directCountsByDepartment(int $companyId): array
    {
        $rows = Position::query()
            ->where('company_id', $companyId)
            ->whereNotNull('department_id')
            ->select('department_id', DB::raw('count(*) as aggregate'))
            ->groupBy('department_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->department_id] = (int) $row->aggregate;
        }

        return $counts;
    }
}
