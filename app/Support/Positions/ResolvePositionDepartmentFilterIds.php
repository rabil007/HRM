<?php

namespace App\Support\Positions;

use App\Models\Department;
use App\Support\Employees\DepartmentDescendantIds;

final class ResolvePositionDepartmentFilterIds
{
    /**
     * Expand a selected department to itself and all descendants (same behavior as employee directory).
     *
     * @return list<int>
     */
    public static function includingDescendants(int $companyId, string $departmentId): array
    {
        if ($departmentId === '' || ! ctype_digit($departmentId)) {
            return [];
        }

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'parent_id'])
            ->map(fn (Department $department): array => [
                'id' => (int) $department->id,
                'parent_id' => $department->parent_id !== null ? (int) $department->parent_id : null,
            ])
            ->all();

        return DepartmentDescendantIds::includingSelf((int) $departmentId, $departments);
    }
}
