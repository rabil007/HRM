<?php

namespace App\Support\Contracts;

use App\Models\EmployeeContract;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class ContractDirectoryQuery
{
    public function __construct(
        private readonly int $companyId,
        private readonly ContractDirectoryFilters $filters,
    ) {}

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->baseQuery();

        $this->applyFilters($query);

        return $query
            ->orderByDesc('employee_contracts.start_date')
            ->orderByDesc('employee_contracts.id')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (EmployeeContract $contract) => ContractListResource::toArray($contract));
    }

    /**
     * @return Builder<EmployeeContract>
     */
    private function baseQuery(): Builder
    {
        $totalContractsSubquery = fn (QueryBuilder $sub): QueryBuilder => $sub
            ->selectRaw('count(*)')
            ->from('employee_contracts as ec_count')
            ->whereColumn('ec_count.employee_id', 'employee_contracts.employee_id')
            ->where('ec_count.company_id', $this->companyId)
            ->whereNull('ec_count.deleted_at');

        return EmployeeContract::query()
            ->where('employee_contracts.company_id', $this->companyId)
            ->addSelect('employee_contracts.*')
            ->selectSub($totalContractsSubquery, 'total_contracts')
            ->with([
                'employee:id,name,employee_no,image,company_id,branch_id,department_id,employee_profile_template_id',
                'employee.employeeProfileTemplate:id,name',
            ]);
    }

    /**
     * @param  Builder<EmployeeContract>  $query
     */
    private function applyFilters(Builder $query): void
    {
        ContractLifecycleFilter::apply($query, $this->filters->lifecycle);

        $query
            ->when($this->filters->status !== '', fn (Builder $inner) => $inner->where('employee_contracts.status', $this->filters->status))
            ->when($this->filters->search !== '', function (Builder $inner): void {
                $search = $this->filters->search;
                $like = '%'.$search.'%';

                $inner->where(function (Builder $searchQuery) use ($like): void {
                    $searchQuery
                        ->where('employee_contracts.labor_contract_id', 'like', $like)
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

                if ($this->filters->payrollCategory !== ''
                    && ContractWorkforceDepartmentScope::isValid($this->filters->payrollCategory)) {
                    ContractWorkforceDepartmentScope::apply(
                        $employeeQuery,
                        $this->companyId,
                        $this->filters->payrollCategory,
                    );
                }
            });
    }
}
