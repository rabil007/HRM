<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Bank;
use App\Support\BankAccounts\BankAccountDirectoryFilters;
use App\Support\BankAccounts\BankAccountDirectoryQuery;
use App\Support\BankAccounts\BankAccountPagePermissions;
use App\Support\BankAccounts\BankAccountSummaryQuery;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BankAccountsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, BankAccountSummaryQuery $summaryQuery)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = BankAccountDirectoryFilters::fromRequest($request);
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = (new BankAccountDirectoryQuery($companyId, $filters))->paginate($perPage);

        $banks = Bank::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('organization/bank-accounts/index', [
            'summary' => $summaryQuery->forCompany($companyId),
            'search' => $filters->search,
            'bank_id' => $filters->bankId,
            'is_primary' => $filters->isPrimary,
            'payment_method' => $filters->paymentMethod,
            'branch_id' => $filters->branchId,
            'department_id' => $filters->departmentId,
            'department_tree' => BuildDepartmentEmployeeTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'bank_accounts' => $paginator->items(),
            'banks' => $banks,
            'pagination' => $this->paginationMeta($paginator),
            'can' => BankAccountPagePermissions::for($request->user()),
        ]);
    }
}
