<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\EmployeeFormOptions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeDocumentsBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, DocumentBrowseQuery $browse, DocumentAiSettings $documentAiSettings)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404, $request->user());

        $employee->loadMissing('employeeProfileTemplate:id,name,configuration_json');

        $result = $browse->documentsForEmployee($companyId, $employee);
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);
        $documentsTabVisible = (bool) ($resolved['tabs']['documents']['visible'] ?? true);

        return Inertia::render('organization/documents/employee', [
            'employee' => $result['employee'],
            'documents' => $result['documents'],
            'summary' => $browse->expirySummary($companyId, $employee->id),
            'countries' => EmployeeFormOptions::for($companyId)['countries'],
            'document_types' => EmployeeFormOptions::documentTypes(),
            'template_fields' => $resolved['fields']['employee_documents'] ?? null,
            'documents_tab_visible' => $documentsTabVisible,
            'can' => DocumentPagePermissions::for($request->user()),
            'document_ai_settings' => $documentAiSettings->propsForCompany($companyId),
        ]);
    }
}
