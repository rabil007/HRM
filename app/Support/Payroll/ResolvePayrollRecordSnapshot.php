<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\EmployeeContract;

final class ResolvePayrollRecordSnapshot
{
    /**
     * @return array{
     *     contract_id: int,
     *     bank_id: int|null,
     *     employee_bank_account_id: int|null,
     * }
     */
    public static function from(Employee $employee, EmployeeContract $contract): array
    {
        $employee->loadMissing('primaryBankAccount');
        $primaryAccount = $employee->primaryBankAccount;

        return [
            'contract_id' => $contract->id,
            'bank_id' => $primaryAccount?->bank_id,
            'employee_bank_account_id' => $primaryAccount?->id,
        ];
    }
}
