<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Contracts\ContractDepartmentTree;
use App\Support\Contracts\ContractDirectoryFilters;
use App\Support\Contracts\ContractPagePermissions;
use App\Support\Contracts\NoContractEmployeesQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContractsNoContractController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, NoContractEmployeesQuery $query): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = ContractDirectoryFilters::fromRequest($request);
        $search = trim((string) $request->query('search', ''));
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = $query->paginate($companyId, $filters, $search, $perPage);

        return Inertia::render('organization/contracts/no-contract', [
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'payroll_category' => $filters->payrollCategory,
            'department_id' => $filters->departmentId,
            'department_tree' => ContractDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
                ContractDepartmentTree::CONTEXT_NO_CONTRACT,
                $filters,
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'can' => ContractPagePermissions::for($request->user()),
        ]);
    }
}
