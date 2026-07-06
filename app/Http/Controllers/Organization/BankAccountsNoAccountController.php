<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\BankAccounts\BankAccountPagePermissions;
use App\Support\BankAccounts\NoBankAccountEmployeesQuery;
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
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = $query->paginate($companyId, $search, $perPage);

        return Inertia::render('organization/bank-accounts/no-account', [
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'can' => BankAccountPagePermissions::for($request->user()),
        ]);
    }
}
