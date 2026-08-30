<?php

namespace App\Support\Documents\Signing;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentSigningFlow;

final class DocumentSigningFlowRetryEligibility
{
    /**
     * Whether a blocked signing flow can be retried using RetryDocumentSigningFlow
     * (current step must have a completed recipient request to advance from).
     */
    public static function canRetry(DocumentSigningFlow $flow): bool
    {
        if ($flow->status !== DocumentSigningFlowStatus::Blocked) {
            return false;
        }

        return DocumentRecipientRequest::query()
            ->forCompany((int) $flow->company_id)
            ->where('document_signing_flow_id', $flow->id)
            ->where('signing_step_sequence', $flow->current_step_sequence)
            ->where('status', DocumentRecipientRequestStatus::Completed)
            ->exists();
    }
}
