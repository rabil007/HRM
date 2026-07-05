<?php

namespace App\Support\Contracts;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Employees\DepartmentDescendantIds;
use Illuminate\Database\Eloquent\Builder;

final class ContractWorkforceDepartmentScope
{
    /**
     * @var list<string>
     */
    private const OFFICE_ROOTS = ['Office'];

    /**
     * @var list<string>
     */
    private const CREW_ROOTS = ['Marine', 'Offshore'];

    public static function isValid(?string $scope): bool
    {
        return in_array($scope, ['office', 'crew'], true);
    }

    /**
     * @return list<int>
     */
    public static function departmentIdsFor(int $companyId, string $scope): array
    {
        if (! self::isValid($scope)) {
            return [];
        }

        $rootNames = $scope === 'office' ? self::OFFICE_ROOTS : self::CREW_ROOTS;

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->get(['id', 'name', 'parent_id']);

        if ($departments->isEmpty()) {
            return [];
        }

        $graph = $departments
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'parent_id' => $department->parent_id,
            ])
            ->all();

        $rootIds = $departments
            ->filter(fn (Department $department): bool => in_array($department->name, $rootNames, true))
            ->pluck('id')
            ->all();

        $departmentIds = [];

        foreach ($rootIds as $rootId) {
            foreach (DepartmentDescendantIds::includingSelf($rootId, $graph) as $departmentId) {
                $departmentIds[] = $departmentId;
            }
        }

        return array_values(array_unique($departmentIds));
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public static function apply(Builder $query, int $companyId, string $scope): void
    {
        $departmentIds = self::departmentIdsFor($companyId, $scope);

        if ($departmentIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('department_id', $departmentIds);
    }
}
