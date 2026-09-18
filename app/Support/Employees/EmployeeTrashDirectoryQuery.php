<?php

namespace App\Support\Employees;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeTrashDirectoryQuery
{
    public static function for(int $companyId, string $search = '', ?User $user = null): Builder
    {
        $query = Employee::onlyTrashed()
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

        if ($user !== null) {
            EmployeeVisibilityScope::apply($query, $user, $companyId);
        }

        return $query;
    }
}
