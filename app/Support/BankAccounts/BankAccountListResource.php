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
            'bank_routing_code' => $account->bank?->uae_routing_code_agent_id,
            'iban' => $account->iban,
            'account_name' => $account->account_name,
            'is_primary' => (bool) $account->is_primary,
            'created_at' => $account->created_at?->toDateTimeString(),
            'employee_id' => $employee?->id ?? $account->employee_id,
            'employee_name' => $employee?->name ?? '',
            'employee_no' => $employee?->employee_no ?? '',
            'employee_image' => $employee?->image,
            'department_name' => $employee?->department?->name,
            'position_title' => $employee?->position?->title,
            'salary_payment_method' => $employee?->salary_payment_method?->value ?? 'bank_transfer',
            'salary_payment_method_label' => $employee?->salary_payment_method?->label() ?? 'Bank transfer',
            'total_bank_accounts' => (int) ($account->total_bank_accounts ?? 1),
        ];
    }
}
