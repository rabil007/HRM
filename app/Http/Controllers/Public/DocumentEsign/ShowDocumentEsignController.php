<?php

namespace App\Http\Controllers\Public\DocumentEsign;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentSignatureLinkService;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowDocumentEsignController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        BulkDocumentSignatureLinkService $links,
    ): Response {
        $signatureRequest = BulkDocumentSignatureRosterQuery::findByToken($token);

        if ($signatureRequest === null) {
            abort(404);
        }

        if ($signatureRequest->isExpired() && $signatureRequest->status === BulkDocumentSignatureRequestStatus::AwaitingSignature) {
            $signatureRequest->update(['status' => BulkDocumentSignatureRequestStatus::Expired]);
            $signatureRequest->refresh();
        }

        $alreadySubmitted = in_array($signatureRequest->status, [
            BulkDocumentSignatureRequestStatus::Submitted,
            BulkDocumentSignatureRequestStatus::Approved,
        ], true);

        return Inertia::render('esign/index', [
            'employeeName' => (string) ($signatureRequest->employee?->name ?? ''),
            'employeeNo' => $signatureRequest->employee?->employee_no,
            'companyName' => (string) ($signatureRequest->company?->name ?? ''),
            'documentLabel' => 'Salary Declaration',
            'expiresAt' => $signatureRequest->expires_at?->toIso8601String(),
            'status' => $signatureRequest->status->value,
            'alreadySubmitted' => $alreadySubmitted,
            'submitUrl' => $links->submitUrl($signatureRequest),
            'downloadUrl' => $links->downloadUnsignedUrl($signatureRequest),
        ]);
    }
}
