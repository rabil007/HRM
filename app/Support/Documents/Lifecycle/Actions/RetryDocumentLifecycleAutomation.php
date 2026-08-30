<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetryDocumentLifecycleAutomation
{
    public function __construct(
        private StartDocumentLifecycleAutomation $start,
        private AdvanceDocumentLifecycleAutomation $advance,
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handle(
        DocumentLifecycleAutomation $lifecycle,
        User $actor,
        int $companyId,
    ): DocumentLifecycleAutomation {
        abort_unless((int) $lifecycle->company_id === $companyId, 404);

        $locked = DB::transaction(function () use ($lifecycle, $actor, $companyId): DocumentLifecycleAutomation {
            /** @var DocumentLifecycleAutomation $locked */
            $locked = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->whereKey($lifecycle->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== DocumentLifecycleAutomationStatus::Blocked) {
                throw ValidationException::withMessages([
                    'lifecycle' => 'Only blocked lifecycle automations can be retried.',
                ]);
            }

            $this->activityLogger->log(
                description: 'Document lifecycle automation retried',
                event: 'document_lifecycle_retried',
                lifecycle: $locked,
                actor: $actor,
                metadata: [
                    'previous_blocked_code' => $locked->blocked_code,
                ],
            );

            return $locked;
        });

        if ($this->shouldAdvanceSigning($locked, $companyId)) {
            return $this->advance->startSnapshottedSigning($locked, $companyId);
        }

        return $this->start->handle((int) $locked->id, $companyId);
    }

    private function shouldAdvanceSigning(DocumentLifecycleAutomation $lifecycle, int $companyId): bool
    {
        if ($lifecycle->stage === DocumentLifecycleAutomationStage::Signing) {
            return true;
        }

        if ($lifecycle->document_signing_flow_id !== null) {
            return true;
        }

        if (! $lifecycle->hasSigning()) {
            return false;
        }

        if ($lifecycle->document_workflow_request_id === null) {
            return false;
        }

        $workflowRequest = DocumentWorkflowRequest::query()
            ->forCompany($companyId)
            ->whereKey($lifecycle->document_workflow_request_id)
            ->first();

        return $workflowRequest instanceof DocumentWorkflowRequest
            && $workflowRequest->status === DocumentWorkflowRequestStatus::Approved;
    }
}
