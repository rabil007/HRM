<?php

namespace App\Support\CrewPlanning;

use App\Models\CrewPlanningSetting;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class CrewPlanningSettings
{
    /**
     * @return list<int>
     */
    public static function poolDepartmentIds(int $companyId): array
    {
        $setting = CrewPlanningSetting::query()
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

    /**
     * @param  list<int>  $departmentIds
     */
    public static function savePoolDepartmentIds(int $companyId, array $departmentIds): CrewPlanningSetting
    {
        $normalized = array_values(array_unique(array_map(intval(...), $departmentIds)));

        return CrewPlanningSetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            ['pool_department_ids' => $normalized === [] ? null : $normalized],
        );
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
     * @return list<array{id: int, name: string, rank_id: int, rank_name: string}>
     */
    public static function poolEmployees(int $companyId): array
    {
        $departmentIds = self::poolDepartmentIds($companyId);

        return Employee::query()
            ->where('employees.company_id', $companyId)
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
