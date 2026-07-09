<?php

namespace App\Http\Controllers\Organization\BulkDocumentSignature;

use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\Response;

class DownloadUnsignedBulkDocumentController extends Controller
{
    public function __invoke(
        string $token,
        DocumentDownloadService $downloads,
    ): Response {
        $signatureRequest = BulkDocumentSignatureRosterQuery::findByToken($token);

        if ($signatureRequest === null || $signatureRequest->employeeDocument === null) {
            abort(404);
        }

        return $downloads->downloadSingleDocument(
            $signatureRequest->employeeDocument,
            $signatureRequest->company_id,
        );
    }
}
