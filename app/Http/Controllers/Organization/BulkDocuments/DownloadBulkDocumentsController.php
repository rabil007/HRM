<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\DownloadBulkDocumentsRequest;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadBulkDocumentsController extends Controller
{
    public function store(
        DownloadBulkDocumentsRequest $request,
        DocumentDownloadService $downloads,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentType = BulkDocumentTypeRegistry::resolveDocumentType(
            (string) $request->input('document_type_key'),
        );

        return $downloads->streamBulkDocumentsZipByType(
            $companyId,
            $documentType->id,
            $request->employeeIds(),
        );
    }
}
