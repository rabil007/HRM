<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentsFolderIndexController extends Controller
{
    public function __invoke(Request $request, DocumentBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = trim((string) $request->query('search', ''));

        $employees = $browse->employeesWithDocuments(
            $companyId,
            $search !== '' ? $search : null,
        )->values()->all();

        return Inertia::render('organization/documents/index', [
            'employees' => $employees,
            'search' => $search,
        ]);
    }
}
