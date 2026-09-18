<?php

namespace App\Support\Contracts;

use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;

final class ContractAccess
{
    public static function assertEmployeeInCompany(
        Employee $employee,
        int $companyId,
        int $status = 403,
        ?User $user = null,
    ): void {
        abort_unless((int) $employee->company_id === $companyId, $status);

        $currentUser = $user ?? auth()->user();
        if ($currentUser instanceof User) {
            abort_unless(EmployeeVisibilityScope::canAccess($currentUser, $employee, $companyId, allowSelf: true), 404);
        }
    }
}
