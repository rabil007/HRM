<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkDocumentIdsRequest;
use App\Models\Employee;
use App\Support\EmployeeDocuments\DocumentAccess;
use App\Support\EmployeeDocuments\DocumentShareService;
use Illuminate\Http\JsonResponse;

class DocumentBulkShareLinksController extends Controller
{
    public function __invoke(
        BulkDocumentIdsRequest $request,
        Employee $employee,
        DocumentShareService $shares,
    ): JsonResponse {
        $companyId = (int) $request->attributes->get('current_company_id');

        DocumentAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $documentIds = array_values(array_map('intval', $request->validated('document_ids')));

        $share = $shares->createFilesShare(
            employee: $employee,
            documentIds: $documentIds,
            companyId: $companyId,
            createdBy: $request->user()?->id,
            password: $request->validated('password'),
            expiresAt: $request->validated('expires_at'),
        );

        $documents = $shares->documentsForShare($share);

        return response()->json([
            'share_url' => $shares->shareUrl($share),
            'documents' => $shares->documentNamePayload($documents, $documentIds),
        ]);
    }
}
