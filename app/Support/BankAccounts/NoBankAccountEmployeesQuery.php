<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class NoBankAccountEmployeesQuery
{
    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $companyId, string $search, int $perPage): LengthAwarePaginator
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('bankAccounts')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_no', 'like', "%{$search}%");
                });
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
