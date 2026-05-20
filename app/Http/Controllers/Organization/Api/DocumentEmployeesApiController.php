<?php

namespace App\Http\Controllers\Organization\Api;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentEmployeesApiController extends Controller
{
    public function __invoke(Request $request, DocumentBrowseQuery $browse): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = trim((string) $request->query('search', ''));

        $employees = $browse->employeesWithDocuments(
            $companyId,
            $search !== '' ? $search : null,
        )->values()->all();

        return response()->json($employees);
    }
}
