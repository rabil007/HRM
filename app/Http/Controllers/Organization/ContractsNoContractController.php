<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Contracts\ContractPagePermissions;
use App\Support\Contracts\NoContractEmployeesQuery;
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
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = $query->paginate($companyId, $search, $perPage);

        return Inertia::render('organization/contracts/no-contract', [
            'employees' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'can' => ContractPagePermissions::for($request->user()),
        ]);
    }
}
