<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkMergePdfDocumentsRequest;
use App\Models\Employee;
use App\Services\DocumentMergeService;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentBulkPdfMergeController extends Controller
{
    public function __invoke(
        BulkMergePdfDocumentsRequest $request,
        Employee $employee,
        DocumentMergeService $merge,
        DocumentBulkActionService $bulkActions,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $documents = $bulkActions->documentsForEmployeeAction(
            $request->validated('document_ids'),
            $companyId,
            $employee->id,
        );

        return $merge->streamMergedPdf($documents, $employee, $request->validated('download_name'));
    }
}
