<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetryDocumentSigningFlow
{
    public function __construct(
        private AdvanceDocumentSigningFlow $advance,
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

            if ($locked->status !== DocumentSigningFlowStatus::Blocked) {
                throw ValidationException::withMessages([
                    'flow' => 'Only blocked signing flows can be retried.',
                ]);
            }

            $completed = DocumentRecipientRequest::query()
                ->forCompany($companyId)
                ->where('document_signing_flow_id', $locked->id)
                ->where('signing_step_sequence', $locked->current_step_sequence)
                ->where('status', DocumentRecipientRequestStatus::Completed)
                ->orderByDesc('id')
                ->first();

            if ($completed === null) {
                throw ValidationException::withMessages([
                    'flow' => 'No completed signing step is available to retry from.',
                ]);
            }

            // Advance from Blocked; do not temporarily force Active.
            $advanced = $this->advance->handle($locked, $completed, $actor);

            if ($advanced->status === DocumentSigningFlowStatus::Active
                || $advanced->status === DocumentSigningFlowStatus::Completed) {
                $this->activityLogger->log(
                    description: 'Document signing flow retry succeeded',
                    event: 'signing_flow_retry_succeeded',
                    flow: $advanced,
                    actor: $actor,
                );
            }

            return $advanced;
        });
    }
}
