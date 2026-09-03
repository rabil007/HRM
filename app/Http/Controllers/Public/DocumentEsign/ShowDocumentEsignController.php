<?php

namespace App\Http\Controllers\Public\DocumentEsign;

use App\Enums\BulkDocumentSignatureRequestStatus;
use App\Http\Controllers\Controller;
use App\Support\BulkDocuments\BulkDocumentSignatureLinkService;
use App\Support\BulkDocuments\BulkDocumentSignatureRosterQuery;
use App\Support\BulkDocuments\BulkDocumentTypeRegistry;
use App\Support\BulkDocuments\LegacySalaryDeclarationSigning;
use App\Support\BulkDocuments\SalaryDeclarationSignaturePlacements;
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

        $legacySigningRetired = BulkDocumentTypeRegistry::legacySigningRetired(
            $signatureRequest->document_type_key,
        );

        if (
            ! $legacySigningRetired
            && $signatureRequest->isExpired()
            && $signatureRequest->status === BulkDocumentSignatureRequestStatus::AwaitingSignature
        ) {
            $signatureRequest->update(['status' => BulkDocumentSignatureRequestStatus::Expired]);
            $signatureRequest->refresh();
        }

        $alreadySubmitted = in_array($signatureRequest->status, [
            BulkDocumentSignatureRequestStatus::Submitted,
            BulkDocumentSignatureRequestStatus::Approved,
        ], true);

        $unavailable = ! $alreadySubmitted && ! $signatureRequest->isSignable();
        $unavailableMessage = $legacySigningRetired && $signatureRequest->status === BulkDocumentSignatureRequestStatus::AwaitingSignature
            ? LegacySalaryDeclarationSigning::PUBLIC_SIGNING_UNAVAILABLE_MESSAGE
            : null;

        $placements = BulkDocumentTypeRegistry::resolveSignaturePlacements(
            $signatureRequest->document_type_key,
        );

        $documentLabel = BulkDocumentTypeRegistry::find($signatureRequest->document_type_key)['label'];

        return Inertia::render('esign/index', [
            'employeeName' => (string) ($signatureRequest->employee?->name ?? ''),
            'employeeNo' => $signatureRequest->employee?->employee_no,
            'companyName' => (string) ($signatureRequest->company?->name ?? ''),
            'documentLabel' => $documentLabel,
            'expiresAt' => $signatureRequest->expires_at?->toIso8601String(),
            'status' => $signatureRequest->status->value,
            'alreadySubmitted' => $alreadySubmitted,
            'unavailable' => $unavailable,
            'unavailableMessage' => $unavailableMessage,
            'submitUrl' => $links->submitUrl($signatureRequest),
            'downloadUrl' => $links->downloadUnsignedUrl($signatureRequest),
            'placement' => $placements ?? SalaryDeclarationSignaturePlacements::config(),
        ]);
    }
}
