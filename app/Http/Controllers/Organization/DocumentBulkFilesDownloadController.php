<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\EmployeeDocument\BulkDocumentIdsRequest;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentBulkFilesDownloadController extends Controller
{
    public function __invoke(BulkDocumentIdsRequest $request, DocumentDownloadService $downloads): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return $downloads->streamBulkDocumentsZip(
            $request->validated('document_ids'),
            $companyId,
            'documents_export.zip',
        );
    }
}
