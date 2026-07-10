<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\BulkDocuments\DownloadApprovedBulkDocumentSignaturesRequest;
use App\Support\BulkDocuments\DownloadApprovedBulkDocumentSignatures;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadApprovedBulkDocumentSignaturesZipController extends Controller
{
    public function __invoke(
        DownloadApprovedBulkDocumentSignaturesRequest $request,
        DownloadApprovedBulkDocumentSignatures $downloads,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $documentTypeKey = (string) $request->input('document_type_key', 'salary_declaration');

        return $downloads->streamZip(
            $companyId,
            $documentTypeKey,
            $request->signatureRequestIds(),
        );
    }
}
