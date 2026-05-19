<?php

namespace App\Support\Employees;

use App\Models\Employee;

final class ResolveEmployeeNavigation
{
    /**
     * @return array{
     *     position: int,
     *     total: int,
     *     previous_id: int|null,
     *     next_id: int|null,
     *     list_query: array<string, string>
     * }|null
     */
    public function resolve(Employee $employee, int $companyId, EmployeeDirectoryFilters $filters): ?array
    {
        $directoryQuery = new EmployeeDirectoryQuery($companyId, $filters);
        $scoped = $directoryQuery->base();

        if (! $scoped->clone()->whereKey($employee->id)->exists()) {
            return null;
        }

        $total = $scoped->clone()->count();

        if ($total === 0) {
            return null;
        }

        $position = $scoped->clone()->where('id', '>=', $employee->id)->count();

        $previousId = $scoped->clone()
            ->where('id', '>', $employee->id)
            ->orderBy('id')
            ->value('id');

        $nextId = $scoped->clone()
            ->where('id', '<', $employee->id)
            ->orderByDesc('id')
            ->value('id');

        return [
            'position' => $position,
            'total' => $total,
            'previous_id' => $previousId !== null ? (int) $previousId : null,
            'next_id' => $nextId !== null ? (int) $nextId : null,
            'list_query' => $filters->toQueryArray(),
        ];
    }
}
