<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentBulkFilesDownloadController extends Controller
{
    public function __invoke(Request $request, DocumentDownloadService $downloads): StreamedResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $validated = $request->validate([
            'document_ids' => ['required', 'array', 'min:1'],
            'document_ids.*' => ['integer', 'distinct'],
        ]);

        return $downloads->streamBulkDocumentsZip(
            $validated['document_ids'],
            $companyId,
            'documents_export.zip',
        );
    }
}
