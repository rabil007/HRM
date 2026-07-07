<?php

namespace App\Exports;

use App\Models\EmployeeBankAccount;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

class BankAccountsExport implements FromQuery, WithHeadings, WithMapping, WithStrictNullComparison
{
    /**
     * @param  Builder<EmployeeBankAccount>  $query
     */
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Employee No',
            'Employee Name',
            'Branch',
            'Department',
            'Position',
            'Bank Name',
            'Bank Routing Code',
            'IBAN / Account No',
            'Account Name',
            'Payment Method',
            'Account Type',
            'Created At',
        ];
    }

    public function map($account): array
    {
        return [
            $account->id,
            $account->employee?->employee_no,
            $account->employee?->name,
            $account->employee?->branch?->name,
            $account->employee?->department?->name,
            $account->employee?->position?->title,
            $account->bank?->name,
            $account->bank?->uae_routing_code_agent_id,
            $account->iban,
            $account->account_name,
            $account->employee?->salary_payment_method?->label() ?? 'Bank transfer',
            $account->is_primary ? 'Primary' : 'Secondary',
            optional($account->created_at)->toDateTimeString(),
        ];
    }
}
