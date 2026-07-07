<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Contracts\ContractDepartmentTree;
use App\Support\Contracts\ContractDirectoryFilters;
use App\Support\Contracts\ContractDirectoryQuery;
use App\Support\Contracts\ContractPagePermissions;
use App\Support\Contracts\ContractSummaryQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContractsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, ContractSummaryQuery $summaryQuery)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = ContractDirectoryFilters::fromRequest($request);
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = (new ContractDirectoryQuery($companyId, $filters))->paginate($perPage);

        return Inertia::render('organization/contracts/index', [
            'summary' => $summaryQuery->forCompany($companyId),
            'lifecycle' => $filters->lifecycle,
            'search' => $filters->search,
            'status' => $filters->status,
            'payroll_category' => $filters->payrollCategory,
            'branch_id' => $filters->branchId,
            'department_id' => $filters->departmentId,
            'contracts' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'department_tree' => ContractDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
                ContractDepartmentTree::CONTEXT_INDEX,
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'can' => ContractPagePermissions::for($request->user()),
        ]);
    }
}

