<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;

final class BankAccountSummaryQuery
{
    /**
     * @return array{
     *     total_bank_accounts: int,
     *     primary_accounts: int,
     *     secondary_accounts: int,
     *     no_account_employees: int
     * }
     */
    public function forCompany(int $companyId): array
    {
        $row = EmployeeBankAccount::query()
            ->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as total_accounts')
            ->selectRaw('SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as primary_accounts')
            ->selectRaw('SUM(CASE WHEN is_primary = 0 THEN 1 ELSE 0 END) as secondary_accounts')
            ->first();

        $noAccountCount = Employee::query()
            ->where('company_id', $companyId)
            ->whereDoesntHave('bankAccounts')
            ->count();

        return [
            'total_bank_accounts' => (int) ($row->total_accounts ?? 0),
            'primary_accounts' => (int) ($row->primary_accounts ?? 0),
            'secondary_accounts' => (int) ($row->secondary_accounts ?? 0),
            'no_account_employees' => $noAccountCount,
        ];
    }
}
