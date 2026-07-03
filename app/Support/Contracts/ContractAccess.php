<?php

namespace App\Support\Contracts;

use App\Models\Employee;

final class ContractAccess
{
    public static function assertEmployeeInCompany(Employee $employee, int $companyId, int $status = 403): void
    {
        abort_unless($employee->company_id === $companyId, $status);
    }
}
