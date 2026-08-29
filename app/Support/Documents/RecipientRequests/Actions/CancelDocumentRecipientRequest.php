<?php

namespace App\Support\Documents\RecipientRequests\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestAccess;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\Signing\Actions\CancelDocumentSigningFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelDocumentRecipientRequest
{
    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
    ) {}

    public function handle(DocumentRecipientRequest $request, User $actor, int $companyId): DocumentRecipientRequest
    {
        DocumentRecipientRequestAccess::assertInCompany($request, $companyId);
        abort_unless($actor->can('documents.recipient-requests.cancel'), 403);

        // Flow-linked requests: acquire FLOW first via CancelDocumentSigningFlow.
        // Do not open a request → flow lock path.
        if ($request->document_signing_flow_id !== null) {
            if ($request->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                throw ValidationException::withMessages([
                    'request' => 'Only awaiting requests can be cancelled.',
                ]);
            }

            $flow = DocumentSigningFlow::query()
                ->whereKey($request->document_signing_flow_id)
                ->where('company_id', $companyId)
                ->first();

            if ($flow !== null && $flow->status->isOpen()) {
                app(CancelDocumentSigningFlow::class)->handle($flow, $actor, $companyId);

                return $request->fresh();
            }
        }

        return DB::transaction(function () use ($request, $actor, $companyId): DocumentRecipientRequest {
            /** @var DocumentRecipientRequest $locked */
            $locked = DocumentRecipientRequest::query()
                ->whereKey($request->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== DocumentRecipientRequestStatus::AwaitingAction) {
                throw ValidationException::withMessages([
                    'request' => 'Only awaiting requests can be cancelled.',
                ]);
            }

            // Linked to a flow that is no longer open — cancel the request alone.
            $locked->update([
                'status' => DocumentRecipientRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'next_reminder_at' => null,
            ]);

            $this->eventRecorder->record(
                $locked,
                DocumentRecipientRequestEventType::RequestCancelled,
                $actor,
            );

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->tap(fn ($activity) => $activity->company_id = $companyId)
                ->withProperties([
                    'action' => 'recipient_request_cancelled',
                    'document_recipient_request_id' => $locked->id,
                    'status' => $locked->status->value,
                ])
                ->log('Recipient request cancelled');

            return $locked->fresh();
        });
    }
}
