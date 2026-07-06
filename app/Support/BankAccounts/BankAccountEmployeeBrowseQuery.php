<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;

final class BankAccountEmployeeBrowseQuery
{
    /**
     * @return array{
     *     employee: array{id: int, name: string, employee_no: string},
     *     bank_accounts: list<array<string, mixed>>
     * }
     */
    public function forEmployee(int $companyId, Employee $employee): array
    {
        $accounts = EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->with(['bank:id,name'])
            ->orderByDesc('is_primary')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmployeeBankAccount $account) => BankAccountListResource::toArray($account, $employee))
            ->values()
            ->all();

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'bank_accounts' => $accounts,
        ];
    }
}
