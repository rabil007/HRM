<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;

final class BankAccountListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(EmployeeBankAccount $account, ?Employee $employee = null): array
    {
        $employee ??= $account->employee;

        return [
            'id' => $account->id,
            'bank_id' => $account->bank_id,
            'bank_name' => $account->bank?->name ?? '',
            'iban' => $account->iban,
            'account_name' => $account->account_name,
            'is_primary' => (bool) $account->is_primary,
            'created_at' => $account->created_at?->toDateTimeString(),
            'employee_id' => $employee?->id ?? $account->employee_id,
            'employee_name' => $employee?->name ?? '',
            'employee_no' => $employee?->employee_no ?? '',
            'employee_image' => $employee?->image,
            'profile_template_name' => $employee?->employeeProfileTemplate?->name,
            'total_bank_accounts' => (int) ($account->total_bank_accounts ?? 1),
        ];
    }
}
