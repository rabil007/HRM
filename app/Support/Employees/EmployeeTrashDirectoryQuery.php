<?php

namespace App\Support\Employees;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeTrashDirectoryQuery
{
    public static function for(int $companyId, string $search = ''): Builder
    {
        return Employee::onlyTrashed()
            ->where('company_id', $companyId)
            ->with([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
            ])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('employee_no', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('work_email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('deleted_at')
            ->orderBy('name')
            ->orderBy('id');
    }
}
