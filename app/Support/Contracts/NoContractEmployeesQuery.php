<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class NoContractEmployeesQuery
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     employee_no: string,
     *     image: string|null,
     *     department: string|null,
     *     position: string|null,
     *     hire_date: string|null
     * }[]
     */
    public function paginate(int $companyId, string $search, string $departmentId, int $perPage): LengthAwarePaginator
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->whereDoesntHave('contracts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
            })
            ->when($departmentId !== '', function ($query) use ($companyId, $departmentId) {
                $directoryFilters = new EmployeeDirectoryFilters(departmentId: $departmentId);

                EmployeeDirectoryQuery::applyAttributeFilters(
                    $query,
                    $companyId,
                    $directoryFilters,
                    exceptDepartment: false,
                    exceptPosition: true,
                );
            })
            ->with(['department:id,name', 'position:id,title'])
            ->orderBy('name')
            ->paginate($perPage)
            ->through(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'hire_date' => $employee->hire_date?->toDateString(),
            ]);
    }
}
