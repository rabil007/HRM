<?php

namespace App\Support\BankAccounts;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\User;
use App\Support\Employees\ActiveEmployeeConstraint;
use App\Support\Employees\EmployeeVisibilityScope;

final class BankAccountSummaryQuery
{
    /**
     * @return array{
     *     total_bank_accounts: int,
     *     primary_accounts: int,
     *     secondary_accounts: int,
     *     ansari_accounts: int,
     *     no_account_employees: int
     * }
     */
    public function forCompany(int $companyId, ?User $user = null): array
    {
        $currentUser = $user ?? auth()->user();

        $accountsQuery = EmployeeBankAccount::query()
            ->where('company_id', $companyId);

        ActiveEmployeeConstraint::whereHas($accountsQuery, $companyId);

        if ($currentUser instanceof User) {
            EmployeeVisibilityScope::whereHas($accountsQuery, $currentUser, $companyId, 'employee');
        }

        $row = $accountsQuery
            ->selectRaw('COUNT(*) as total_accounts')
            ->selectRaw('SUM(CASE WHEN is_primary = 1 THEN 1 ELSE 0 END) as primary_accounts')
            ->selectRaw('SUM(CASE WHEN is_primary = 0 THEN 1 ELSE 0 END) as secondary_accounts')
            ->first();

        $ansariQuery = EmployeeBankAccount::query()
            ->where('employee_bank_accounts.company_id', $companyId)
            ->whereHas('employee', function ($query) use ($companyId, $currentUser) {
                ActiveEmployeeConstraint::apply($query, $companyId)
                    ->where('salary_payment_method', 'cash_ansari');

                if ($currentUser instanceof User) {
                    EmployeeVisibilityScope::apply($query, $currentUser, $companyId);
                }
            });

        $ansariCount = $ansariQuery->count();

        $noAccountQuery = Employee::query()
            ->where('company_id', $companyId)
            ->active()
            ->whereDoesntHave('bankAccounts');

        if ($currentUser instanceof User) {
            EmployeeVisibilityScope::apply($noAccountQuery, $currentUser, $companyId);
        }

        $noAccountCount = $noAccountQuery->count();

        return [
            'total_bank_accounts' => (int) ($row->total_accounts ?? 0),
            'primary_accounts' => (int) ($row->primary_accounts ?? 0),
            'secondary_accounts' => (int) ($row->secondary_accounts ?? 0),
            'ansari_accounts' => $ansariCount,
            'no_account_employees' => $noAccountCount,
        ];
    }
}
