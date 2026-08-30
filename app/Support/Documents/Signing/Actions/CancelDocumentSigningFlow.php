<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientRequestEventType;
use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use App\Support\Documents\RecipientRequests\DocumentRecipientRequestEventRecorder;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelDocumentSigningFlow
{
    public function __construct(
        private DocumentRecipientRequestEventRecorder $eventRecorder,
        private DocumentSigningFlowActivityLogger $activityLogger,
    ) {}

    public function handle(DocumentSigningFlow $flow, User $actor, int $companyId): DocumentSigningFlow
    {
        abort_unless((int) $flow->company_id === $companyId, 404);

        return DB::transaction(function () use ($flow, $actor, $companyId): DocumentSigningFlow {
            /** @var DocumentSigningFlow $locked */
            $locked = DocumentSigningFlow::query()
                ->whereKey($flow->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'flow' => 'Only active or blocked signing flows can be cancelled.',
                ]);
            }

            $awaiting = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_signing_flow_id', $locked->id)
                ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
                ->lockForUpdate()
                ->get();

            foreach ($awaiting as $request) {
                $request->update([
                    'status' => DocumentRecipientRequestStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'next_reminder_at' => null,
                ]);

                $this->eventRecorder->record(
                    $request,
                    DocumentRecipientRequestEventType::RequestCancelled,
                    $actor,
                );
            }

            $locked->update([
                'status' => DocumentSigningFlowStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'blocked_at' => null,
                'blocked_reason' => null,
            ]);

            $this->activityLogger->log(
                description: 'Document signing flow cancelled',
                event: 'signing_flow_cancelled',
                flow: $locked->fresh(),
                actor: $actor,
            );

            $flowId = (int) $locked->id;
            DB::afterCommit(function () use ($flowId, $companyId): void {
                try {
                    app(SyncDocumentLifecycleFromSigningFlow::class)->handle($flowId, $companyId);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return $locked->fresh();
        });
    }
}
