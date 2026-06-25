<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkDocumentIdsRequest;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentBulkActionService;
use App\Support\EmployeeDocuments\DocumentShareLinkService;
use Illuminate\Http\JsonResponse;

class DocumentBulkShareLinksController extends Controller
{
    public function __invoke(
        BulkDocumentIdsRequest $request,
        Employee $employee,
        DocumentBulkActionService $bulkActions,
        DocumentShareLinkService $shareLinks,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $documents = $bulkActions->documentsForEmployeeAction(
            $request->validated('document_ids'),
            $companyId,
            $employee->id,
        );

        return response()->json([
            'documents' => $shareLinks->sharePayload(
                $documents,
                $request->validated('password'),
                $request->validated('expires_at')
            ),
        ]);
    }
}
