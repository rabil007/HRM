<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\EmployeeDocuments\DocumentShowBackNavigation;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentShowController extends Controller
{
    public function __invoke(Request $request, Employee $employee, EmployeeDocument $document)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);
        DocumentAccess::assertDocumentBelongsToEmployee($employee, $document, $companyId, 404);
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->load([
            'documentType:id,title',
            'uploader:id,name',
            'versions.replacer:id,name',
        ]);

        return Inertia::render('organization/documents/show', [
            'document' => $document->toShowArray(),
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'email' => $employee->work_email ?: $employee->personal_email,
                'phone' => $employee->phone,
            ],
            'countries' => EmployeeFormOptions::for($companyId, $employee)['countries'],
            'can' => DocumentPagePermissions::for($request->user()),
            'back' => DocumentShowBackNavigation::resolve($request, $employee),
        ]);
    }
}
