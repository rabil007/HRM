<?php

namespace App\Http\Controllers\Public\DocumentAction;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentInstanceStorage;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDocumentActionDocumentController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DocumentRecipientRequestEventRecorder $eventRecorder,
    ): StreamedResponse {
        $recipientRequest = DocumentRecipientRequestToken::findByRawToken($token);

        if ($recipientRequest === null) {
            abort(404);
        }

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

        $path = DocumentInstanceStorage::validatedRelativePath($version->file_path, (int) $recipientRequest->company_id);
        abort_if($path === null, 404);
        abort_unless(Storage::disk(DocumentInstanceStorage::DISK)->exists($path), 404);

        $eventRecorder->record(
            $recipientRequest,
            DocumentRecipientRequestEventType::DocumentDownloaded,
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
