<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;

final class BankAccountAccess
{
    public static function assertEmployeeInCompany(Employee $employee, int $companyId, int $status = 403): void
    {
        abort_unless($employee->company_id === $companyId, $status);
    }
}
