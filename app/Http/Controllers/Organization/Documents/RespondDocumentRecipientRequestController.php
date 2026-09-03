<?php

namespace App\Http\Controllers\Organization\Documents;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\DocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\Actions\ExpireDocumentRecipientRequest;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\RecipientRequests\DocumentSignaturePlacementValidator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RespondDocumentRecipientRequestController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentRecipientRequest $recipientRequest,
        DocumentRecipientRequestEventRecorder $eventRecorder,
        ExpireDocumentRecipientRequest $expireRequest,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');
        $user = $request->user();

        abort_if($user === null, 403);

        DocumentRecipientRequestAccess::assertAssignedCompanySignatory($recipientRequest, $user, $companyId);

        if ($recipientRequest->isExpired() && $recipientRequest->status === DocumentRecipientRequestStatus::AwaitingAction) {
            $expireRequest->handle($recipientRequest, $companyId);
            $recipientRequest->refresh();
        }

        if ($recipientRequest->first_viewed_at === null && $recipientRequest->status === DocumentRecipientRequestStatus::AwaitingAction) {
            $recipientRequest->update(['first_viewed_at' => now()]);
            $eventRecorder->record(
                $recipientRequest,
                DocumentRecipientRequestEventType::LinkViewed,
                $user,
                ipAddress: $request->ip(),
                userAgent: (string) $request->userAgent(),
            );
        }

        $recipientRequest->loadMissing(['documentInstance.employeeDocument.employee', 'sourceVersion', 'documentInstance.templateVersion']);

        $slotKey = filled($recipientRequest->signature_slot_key)
            ? (string) $recipientRequest->signature_slot_key
            : 'subject';

        return Inertia::render('organization/documents/recipient-requests/respond', [
            'request' => [
                'id' => $recipientRequest->id,
                'status' => $recipientRequest->status->value,
                'recipient_role_label' => $recipientRequest->recipient_role->label(),
                'document_title' => $recipientRequest->documentInstance?->title_snapshot,
                'employee_name' => $recipientRequest->documentInstance?->employee_name_snapshot,
                'source_version' => $recipientRequest->sourceVersion?->version,
                'expires_at' => $recipientRequest->expires_at?->toIso8601String(),
                'already_completed' => $recipientRequest->status === DocumentRecipientRequestStatus::Completed,
                'signature_placement_count' => count(DocumentSignaturePlacementValidator::placementIdsForSlot(
                    $recipientRequest->documentInstance?->templateVersion?->signature_placement_config,
                    $slotKey,
                )),
            ],
            'document_url' => route('organization.documents.recipient-requests.document', [
                'recipientRequest' => $recipientRequest->id,
            ]),
            'submit_sign_url' => route('organization.documents.recipient-requests.sign', [
                'recipientRequest' => $recipientRequest->id,
            ]),
        ]);
    }
}
