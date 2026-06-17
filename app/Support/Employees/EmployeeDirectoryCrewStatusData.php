<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\EmployeeDeployment;
use Illuminate\Support\Collection;

final class EmployeeDirectoryCrewStatusData
{
    /**
     * @param  Collection<int, Employee>  $employees
     * @return Collection<int, EmployeeDeployment>
     */
    public static function latestDeploymentsFor(Collection $employees, int $companyId): Collection
    {
        $employeeIds = $employees->pluck('id')->filter()->values();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return EmployeeDeployment::query()
            ->where('company_id', $companyId)
            ->whereIn('employee_id', $employeeIds)
            ->with('vessel:id,name')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $group): EmployeeDeployment => $group->first());
    }
}
