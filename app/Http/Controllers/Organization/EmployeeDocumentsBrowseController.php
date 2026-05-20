<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentsBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, DocumentBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $result = $browse->documentsForEmployee($companyId, $employee->id);

        return Inertia::render('organization/documents/employee', [
            'employee' => $result['employee'],
            'documents' => $result['documents'],
        ]);
    }
}
