<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentRecipientRequestDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        DocumentRecipientRequestEventRecorder $eventRecorder,
    ): StreamedResponse {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        abort_if($user === null, 403);

        DocumentRecipientRequestAccess::assertAssignedCompanySignatory($recipientRequest, $user, $companyId);

        if (! in_array($recipientRequest->status, [
            DocumentRecipientRequestStatus::AwaitingAction,
            DocumentRecipientRequestStatus::Completed,
        ], true)) {
            abort(404);
        }

        if ($recipientRequest->isExpired() && $recipientRequest->status === DocumentRecipientRequestStatus::AwaitingAction) {
            abort(404);
        }

        $recipientRequest->loadMissing('sourceVersion');
        $version = $recipientRequest->sourceVersion;

        if ($version === null) {
            abort(404);
        }

        $path = DocumentInstanceStorage::validatedRelativePath($version->file_path, $companyId);
        abort_if($path === null, 404);
        abort_unless(Storage::disk(DocumentInstanceStorage::DISK)->exists($path), 404);

        $eventRecorder->record(
            $recipientRequest,
            DocumentRecipientRequestEventType::DocumentDownloaded,
            $user,
            ipAddress: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        $filename = $version->original_filename ?: 'document.pdf';

        return Storage::disk(DocumentInstanceStorage::DISK)->response(
            $path,
            $filename,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.addslashes($filename).'"',
            ],
        );
    }
}
