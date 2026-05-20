<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkDocumentIdsRequest;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use Illuminate\Http\RedirectResponse;

class DocumentBulkFilesDeleteController extends Controller
{
    public function __invoke(
        BulkDocumentIdsRequest $request,
        Employee $employee,
        DocumentBulkActionService $bulkActions,
    ): RedirectResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId);

        $deleted = $bulkActions->deleteDocuments(
            $request->validated('document_ids'),
            $companyId,
            $employee->id,
        );

        if ($deleted === 0) {
            return back()->with('error', 'No documents could be deleted.');
        }

        $label = $deleted === 1 ? '1 document' : "{$deleted} documents";

        return back()->with('success', "Deleted {$label}.");
    }
}
