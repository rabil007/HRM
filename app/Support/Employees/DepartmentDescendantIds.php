<?php

namespace App\Support\Employees;

use Illuminate\Support\Collection;

final class DepartmentDescendantIds
{
    /**
     * @param  iterable<int, array{id: int, parent_id: int|null}>|Collection<int, array{id: int, parent_id: int|null}>  $departments
     * @return list<int>
     */
    public static function includingSelf(int $departmentId, iterable $departments): array
    {
        $childrenByParent = [];

        foreach ($departments as $department) {
            $parentId = $department['parent_id'] ?? null;

            if ($parentId === null) {
                continue;
            }

            $childrenByParent[$parentId][] = $department['id'];
        }

        $ids = [$departmentId];
        $queue = [$departmentId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenByParent[$current] ?? [] as $childId) {
                if (in_array($childId, $ids, true)) {
                    continue;
                }

                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }
}
