<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\PayrollRecord;

final class EmployeePrimaryAccountResource
{
    /**
     * @return array{bank_name: string|null, account_name: string|null, iban: string|null}|null
     */
    public static function forPayrollRecord(PayrollRecord $record): ?array
    {
        $account = $record->resolvedEmployeeBankAccount();

        if ($account === null) {
            return null;
        }

        $bank = $record->resolvedBank();

        return [
            'bank_name' => $bank?->name ?? $account->bank?->name,
            'account_name' => $account->account_name,
            'iban' => $account->iban,
        ];
    }

    /**
     * @return array{bank_name: string|null, account_name: string|null, iban: string|null}|null
     */
    public static function forEmployee(?Employee $employee): ?array
    {
        $account = $employee?->primaryBankAccount;

        if ($account === null) {
            return null;
        }

        return [
            'bank_name' => $account->bank?->name,
            'account_name' => $account->account_name,
            'iban' => $account->iban,
        ];
    }
}
