<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Contracts\ContractDepartmentTree;
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
        $search = (string) $request->query('search', '');
        $departmentId = (string) $request->query('department_id', '');
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = $query->paginate($companyId, $search, $departmentId, $perPage);

        return Inertia::render('organization/contracts/no-contract', [
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'department_id' => $departmentId,
            'department_tree' => ContractDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $departmentId),
                ContractDepartmentTree::CONTEXT_NO_CONTRACT,
            ),
            'department_tree_selected_id' => $departmentId !== '' ? (int) $departmentId : null,
            'can' => ContractPagePermissions::for($request->user()),
        ]);
    }
}

