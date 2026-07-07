<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\BankAccounts\BankAccountDepartmentTree;
use App\Support\BankAccounts\BankAccountPagePermissions;
use App\Support\BankAccounts\NoBankAccountEmployeesQuery;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountsNoAccountController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, NoBankAccountEmployeesQuery $query): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = (string) $request->query('search', '');
        $paymentMethod = (string) $request->query('payment_method', '');
        $departmentId = (string) $request->query('department_id', '');
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = $query->paginate($companyId, $search, $paymentMethod, $departmentId, $perPage);

        return Inertia::render('organization/bank-accounts/no-account', [
            'summary' => $query->summary($companyId),
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'payment_method' => $paymentMethod,
            'department_id' => $departmentId,
            'department_tree' => BankAccountDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $departmentId),
                BankAccountDepartmentTree::CONTEXT_NO_ACCOUNT,
            ),
            'department_tree_selected_id' => $departmentId !== '' ? (int) $departmentId : null,
            'can' => BankAccountPagePermissions::for($request->user()),
        ]);
    }
}
