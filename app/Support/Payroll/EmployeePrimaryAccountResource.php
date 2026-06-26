<?php

namespace App\Support\Payroll;

use App\Models\Employee;

final class EmployeePrimaryAccountResource
{
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
