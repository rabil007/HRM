<?php

namespace App\Http\Controllers\Public\DocumentEsign;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\EmployeeDocuments\DocumentDownloadService;
use Symfony\Component\HttpFoundation\Response;

class DownloadDocumentEsignController extends Controller
{
    public function __invoke(
        string $token,
        DocumentDownloadService $downloads,
    ): Response {
        $signatureRequest = BulkDocumentSignatureRosterQuery::findByToken($token);

        if ($signatureRequest === null || $signatureRequest->employeeDocument === null) {
            abort(404);
        }

        if ($signatureRequest->isExpired() && $signatureRequest->status === BulkDocumentSignatureRequestStatus::AwaitingSignature) {
            $signatureRequest->update(['status' => BulkDocumentSignatureRequestStatus::Expired]);
        }

        abort_unless(in_array($signatureRequest->fresh()->status, [
            BulkDocumentSignatureRequestStatus::AwaitingSignature,
            BulkDocumentSignatureRequestStatus::Submitted,
            BulkDocumentSignatureRequestStatus::Approved,
        ], true), 404);

        return $downloads->downloadSingleDocument(
            $signatureRequest->employeeDocument,
            $signatureRequest->company_id,
        );
    }
}
