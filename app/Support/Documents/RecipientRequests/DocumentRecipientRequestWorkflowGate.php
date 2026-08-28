<?php

namespace App\Support\Documents\RecipientRequests;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentWorkflowRequest;
use Illuminate\Validation\ValidationException;

final class DocumentRecipientRequestWorkflowGate
{
    public function assertCanCreateForVersion(DocumentInstance $instance, int $companyId): void
    {
        if ((int) $instance->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'document' => 'Document not found.',
            ]);
        }

        $workflows = DocumentWorkflowRequest::query()
            ->where('company_id', $companyId)
            ->where('document_instance_id', $instance->id)
            ->where('document_instance_version_id', $instance->current_version_id)
            ->orderByDesc('id')
            ->get();

        if ($workflows->isEmpty()) {
            return;
        }

        $latest = $workflows->first();

        if ($latest?->status === DocumentWorkflowRequestStatus::Approved) {
            return;
        }

        throw ValidationException::withMessages([
            'action' => match ($latest?->status) {
                DocumentWorkflowRequestStatus::Pending => 'Internal review or approval is still pending for this document version.',
                DocumentWorkflowRequestStatus::Rejected => 'Internal approval was rejected for this document version.',
                DocumentWorkflowRequestStatus::Cancelled => 'Internal approval was cancelled for this document version.',
                default => 'Internal approval is required before requesting recipient action.',
            },
        ]);
    }

    public function latestApprovedWorkflowId(DocumentInstance $instance, int $versionId, int $companyId): ?int
    {
        return DocumentWorkflowRequest::query()
            ->where('company_id', $companyId)
            ->where('document_instance_id', $instance->id)
            ->where('document_instance_version_id', $versionId)
            ->where('status', DocumentWorkflowRequestStatus::Approved)
            ->orderByDesc('id')
            ->value('id');
    }
}
