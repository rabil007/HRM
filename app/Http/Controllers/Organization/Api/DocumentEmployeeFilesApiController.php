<?php

namespace App\Http\Controllers\Organization\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentEmployeeFilesApiController extends Controller
{
    public function __invoke(Request $request, Employee $employee, DocumentBrowseQuery $browse): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $result = $browse->documentsForEmployee($companyId, $employee->id);

        return response()->json($result['documents']);
    }
}
