<?php

namespace App\Support\BankAccounts;

use App\Models\EmployeeBankAccount;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class BankAccountDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly BankAccountDirectoryFilters $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query);

        return $query
            ->orderByDesc('employee_bank_accounts.is_primary')
            ->orderByDesc('employee_bank_accounts.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeBankAccount $account) => BankAccountListResource::toArray($account));
    }

    /**
     * @return Builder<EmployeeBankAccount>
     */
    private function baseQuery(): Builder
    {
        $totalAccountsSubquery = fn (QueryBuilder $sub): QueryBuilder => $sub
            ->selectRaw('count(*)')
            ->from('employee_bank_accounts as eba_count')
            ->whereColumn('eba_count.employee_id', 'employee_bank_accounts.employee_id')
            ->where('eba_count.company_id', $this->companyId)
            ->whereNull('eba_count.deleted_at');

        return EmployeeBankAccount::query()
            ->where('employee_bank_accounts.company_id', $this->companyId)
            ->addSelect('employee_bank_accounts.*')
            ->selectSub($totalAccountsSubquery, 'total_bank_accounts')
            ->with([
                'bank:id,name',
                'employee:id,name,employee_no,image,company_id,branch_id,department_id,position_id,salary_payment_method',
                'employee.department:id,name',
                'employee.position:id,title',
            ]);
    }

    /**
     * @param  Builder<EmployeeBankAccount>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $query
            ->when($this->filters->bankId !== '', fn (Builder $inner) => $inner->where('employee_bank_accounts.bank_id', $this->filters->bankId))
            ->when($this->filters->isPrimary !== '', function (Builder $inner): void {
                if ($this->filters->isPrimary === 'primary') {
                    $inner->where('employee_bank_accounts.is_primary', true);
                } elseif ($this->filters->isPrimary === 'secondary') {
                    $inner->where('employee_bank_accounts.is_primary', false);
                }
            })
            ->when($this->filters->paymentMethod !== '', function (Builder $inner): void {
                $inner->whereHas('employee', function (Builder $employeeQuery): void {
                    $employeeQuery->where('salary_payment_method', $this->filters->paymentMethod);
                });
            })
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $search = $this->filters->search;
                $like = '%'.$search.'%';

                $inner->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('employee_bank_accounts.iban', 'like', $like)
                        ->orWhere('employee_bank_accounts.account_name', 'like', $like)
                        ->orWhereHas('bank', function (Builder $bankQuery) use ($like): void {
                            $bankQuery->where('name', 'like', $like);
                        })
                        ->orWhereHas('employee', function (Builder $employeeQuery) use ($like): void {
                            $employeeQuery
                                ->where('name', 'like', $like)
                                ->orWhere('employee_no', 'like', $like);
                        });
                });
            })
            ->whereHas('employee', function (Builder $employeeQuery): void {
                $directoryFilters = new EmployeeDirectoryFilters(
                    branchId: $this->filters->branchId,
                    departmentId: $this->filters->departmentId,
                );

                EmployeeDirectoryQuery::applyAttributeFilters(
                    $employeeQuery,
                    $this->companyId,
                    $directoryFilters,
                    exceptDepartment: false,
                    exceptPosition: true,
                );
            });
    }
}
