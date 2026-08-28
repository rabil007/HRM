<?php

namespace App\Http\Controllers\Public\DocumentAction;

use App\Enums\DocumentRecipientAction;
use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Http\Controllers\Controller;
use App\Support\Documents\RecipientRequests\Actions\SubmitDocumentRecipientAcknowledgement;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestLinkService;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestToken;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowDocumentActionController extends Controller
{
    public function __invoke(
        Request $request,
        string $token,
        DocumentRecipientRequestLinkService $links,
        DocumentRecipientRequestEventRecorder $eventRecorder,
    ): Response {
        $recipientRequest = DocumentRecipientRequestToken::findByRawToken($token);

        if ($recipientRequest === null) {
            abort(404);
        }

        DocumentRecipientRequestAccess::assertPublicTokenRecipient($recipientRequest);

        if ($recipientRequest->isExpired() && $recipientRequest->status === DocumentRecipientRequestStatus::AwaitingAction) {
            $recipientRequest->update(['status' => DocumentRecipientRequestStatus::Expired]);
            $eventRecorder->record($recipientRequest, DocumentRecipientRequestEventType::RequestExpired);
            $recipientRequest->refresh();
        }

        if ($recipientRequest->first_viewed_at === null && $recipientRequest->status === DocumentRecipientRequestStatus::AwaitingAction) {
            $recipientRequest->update(['first_viewed_at' => now()]);
            $eventRecorder->record(
                $recipientRequest,
                DocumentRecipientRequestEventType::LinkViewed,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );
        }

        $alreadyCompleted = $recipientRequest->status === DocumentRecipientRequestStatus::Completed;

        $recipientRequest->loadMissing(['company', 'documentInstance']);

        return Inertia::render('document-action/index', [
            'companyName' => (string) ($recipientRequest->company?->name ?? ''),
            'documentTitle' => (string) ($recipientRequest->documentInstance?->title_snapshot ?? 'Document'),
            'employeeName' => $recipientRequest->recipient_name_snapshot,
            'expiresAt' => $recipientRequest->expires_at?->toIso8601String(),
            'status' => $recipientRequest->status->value,
            'action' => $recipientRequest->action->value,
            'alreadyCompleted' => $alreadyCompleted,
            'documentUrl' => $links->documentUrl($recipientRequest, $token),
            'submitSignUrl' => $recipientRequest->action === DocumentRecipientAction::Sign
                ? $links->signUrl($token)
                : null,
            'submitAcknowledgeUrl' => $recipientRequest->action === DocumentRecipientAction::Acknowledge
                ? $links->acknowledgeUrl($token)
                : null,
            'acknowledgementStatement' => SubmitDocumentRecipientAcknowledgement::ACKNOWLEDGEMENT_STATEMENT,
        ]);
    }
}
