<?php

namespace App\Support\Payroll;

use App\Models\PayrollRecord;

final class PayrollRecordLinkage
{
    public static function employeeHasRecords(int $employeeId): bool
    {
        return PayrollRecord::query()
            ->where('employee_id', $employeeId)
            ->exists();
    }

    public static function contractHasRecords(int $contractId): bool
    {
        return PayrollRecord::query()
            ->where('contract_id', $contractId)
            ->exists();
    }

    public static function bankHasRecords(int $bankId): bool
    {
        return PayrollRecord::query()
            ->where('bank_id', $bankId)
            ->exists();
    }

    public static function employeeBankAccountHasRecords(int $accountId): bool
    {
        return PayrollRecord::query()
            ->where('employee_bank_account_id', $accountId)
            ->exists();
    }
}
