<?php

namespace App\Support\Documents\Signing\Actions;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;
use App\Models\User;
use App\Support\Documents\Signing\DocumentSigningFlowActivityLogger;
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

        if ($flow->status !== DocumentSigningFlowStatus::Blocked) {
            throw ValidationException::withMessages([
                'flow' => 'Only blocked signing flows can be retried.',
            ]);
        }

        $completed = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('document_signing_flow_id', $flow->id)
            ->where('signing_step_sequence', $flow->current_step_sequence)
            ->where('status', DocumentRecipientRequestStatus::Completed)
            ->orderByDesc('id')
            ->first();

        if ($completed === null) {
            throw ValidationException::withMessages([
                'flow' => 'No completed signing step is available to retry from.',
            ]);
        }

        // Temporarily mark active so advance proceeds from blocked state.
        $flow->update([
            'status' => DocumentSigningFlowStatus::Active,
        ]);

        $advanced = $this->advance->handle($flow->fresh(), $completed, $actor);

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
    }
}
