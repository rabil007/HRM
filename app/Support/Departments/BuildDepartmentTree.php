<?php

namespace App\Support\Departments;

use App\Models\Department;

final class BuildDepartmentTree
{
    /**
     * @return list<array{id: int, name: string, children: list<mixed>}>
     */
    public static function forCompany(int $companyId): array
    {
        $departments = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id']);

        if ($departments->isEmpty()) {
            return [];
        }

        $departmentIdSet = array_fill_keys($departments->pluck('id')->all(), true);
        $childrenByParent = [];

        foreach ($departments as $department) {
            $parentId = $department->parent_id;

            if ($parentId !== null && isset($departmentIdSet[$parentId])) {
                $childrenByParent[$parentId][] = $department->id;
            }
        }

        $buildNode = function (int $departmentId) use (
            &$buildNode,
            $departments,
            $childrenByParent,
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
                'children' => array_map(
                    fn (int $childId): array => $buildNode($childId),
                    $childIds,
                ),
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

        return array_map(
            fn (int $rootId): array => $buildNode($rootId),
            $rootIds,
        );
    }
}
