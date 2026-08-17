<?php

namespace App\Support\Payroll\CrewTimeline;

use App\Models\CrewTimesheetPreparation;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use Illuminate\Database\Eloquent\Builder;

final class CrewTimelineDepartmentTree
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
        CrewTimesheetPreparation $preparation,
        EmployeeDirectoryFilters $directoryFilters,
    ): array {
        $employeeIds = $preparation->lines
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return BuildDepartmentEmployeeTree::for(
            $companyId,
            $directoryFilters,
            function (Builder $query) use ($companyId, $employeeIds): void {
                $query->where('company_id', $companyId)
                    ->whereIn('id', $employeeIds === [] ? [0] : $employeeIds);
            },
        );
    }
}
