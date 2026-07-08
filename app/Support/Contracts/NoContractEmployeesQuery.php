<?php

namespace App\Support\Contracts;

use App\Models\Employee;
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
    public function paginate(
        int $companyId,
        ContractDirectoryFilters $filters,
        string $search,
        int $perPage,
    ): LengthAwarePaginator {
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
            ->tap(function ($query) use ($companyId, $filters): void {
                ContractDirectoryEmployeeScope::apply($query, $companyId, $filters);
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
