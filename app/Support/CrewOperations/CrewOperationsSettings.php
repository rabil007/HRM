<?php

namespace App\Support\CrewOperations;

use App\Models\CrewOperationsSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Support\Departments\BuildDepartmentTree;
use App\Support\Employees\DepartmentDescendantIds;
use Illuminate\Database\Eloquent\Builder;

final class CrewOperationsSettings
{
    /**
     * @return list<int>
     */
    public static function poolDepartmentIds(int $companyId): array
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        if ($setting === null || $setting->pool_department_ids === null) {
            return [];
        }

        return array_values(array_unique(array_map(
            intval(...),
            array_filter($setting->pool_department_ids, fn ($id) => is_numeric($id)),
        )));
    }

    public static function maxHomeDays(int $companyId): int
    {
        $setting = CrewOperationsSetting::query()
            ->where('company_id', $companyId)
            ->first();

        return $setting?->max_home_days ?? 30;
    }

    /**
     * @param  list<int>  $departmentIds
     */
    public static function saveSettings(int $companyId, array $departmentIds, int $maxHomeDays): CrewOperationsSetting
    {
        $normalized = array_values(array_unique(array_map(intval(...), $departmentIds)));

        return CrewOperationsSetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'pool_department_ids' => $normalized === [] ? null : $normalized,
                'max_home_days' => $maxHomeDays,
            ],
        );
    }

    /**
     * Expands configured pool departments to include all descendant departments.
     *
     * @return list<int>
     */
    public static function expandedPoolDepartmentIds(int $companyId): array
    {
        $selected = self::poolDepartmentIds($companyId);

        if ($selected === []) {
            return [];
        }

        $departments = Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'parent_id'])
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'parent_id' => $department->parent_id,
            ])
            ->all();

        $expanded = [];

        foreach ($selected as $departmentId) {
            $expanded = array_merge(
                $expanded,
                DepartmentDescendantIds::includingSelf($departmentId, $departments),
            );
        }

        return array_values(array_unique($expanded));
    }

    /**
     * @return list<array{id: int, name: string, children: list<mixed>}>
     */
    public static function activeDepartmentTree(int $companyId): array
    {
        return BuildDepartmentTree::forCompany($companyId);
    }

    /**
     * @return list<int>
     */
    public static function allActiveDepartmentIds(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public static function activeDepartments(int $companyId): array
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * All active ranked employees for the planning crew sidebar and assign picker.
     *
     * Filtered by configured pool departments (including descendants) when set.
     * Does not exclude employees based on deployment or crew availability status.
     *
     * @return list<array{id: int, name: string, rank_id: int, rank_name: string}>
     */
    public static function poolEmployees(int $companyId): array
    {
        $departmentIds = self::expandedPoolDepartmentIds($companyId);

        return Employee::query()
            ->where('employees.company_id', $companyId)
            ->active()
            ->whereNull('employees.termination_date')
            ->whereNotNull('employees.rank_id')
            ->when($departmentIds !== [], fn (Builder $q) => $q->whereIn('employees.department_id', $departmentIds))
            ->join('ranks', 'employees.rank_id', '=', 'ranks.id')
            ->whereNull('ranks.deleted_at')
            ->where('ranks.is_active', true)
            ->orderBy('employees.name')
            ->get([
                'employees.id',
                'employees.name',
                'employees.rank_id',
                'ranks.name as rank_name',
            ])
            ->map(fn (Employee $employee) => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'rank_id' => (int) $employee->rank_id,
                'rank_name' => (string) $employee->rank_name,
            ])
            ->all();
    }
}
