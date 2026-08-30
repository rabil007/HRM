<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\Signing\Actions\RetryDocumentSigningFlow;
use App\Support\Documents\Signing\DocumentSigningFlowRetryEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RetryDocumentLifecycleAutomation
{
    public function __construct(
        private StartDocumentLifecycleAutomation $start,
        private AdvanceDocumentLifecycleAutomation $advance,
        private RetryDocumentSigningFlow $retrySigningFlow,
        private SyncDocumentLifecycleFromSigningFlow $syncFromSigningFlow,
        private StopDocumentLifecycleAutomation $stop,
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

        if ($locked->document_signing_flow_id !== null) {
            return $this->recoverExistingSigningFlow($locked, $actor, $companyId);
        }

        if ($locked->document_workflow_request_id !== null) {
            return $this->recoverExistingWorkflow($locked, $companyId);
        }

        return $this->start->handle((int) $locked->id, $companyId);
    }

    private function recoverExistingSigningFlow(
        DocumentLifecycleAutomation $lifecycle,
        User $actor,
        int $companyId,
    ): DocumentLifecycleAutomation {
        $flow = DocumentSigningFlow::query()
            ->forCompany($companyId)
            ->whereKey($lifecycle->document_signing_flow_id)
            ->first();

        if (! $flow instanceof DocumentSigningFlow) {
            throw ValidationException::withMessages([
                'lifecycle' => 'The linked signing flow could not be found for this company.',
            ]);
        }

        return match ($flow->status) {
            DocumentSigningFlowStatus::Completed,
            DocumentSigningFlowStatus::Active,
            DocumentSigningFlowStatus::Cancelled => $this->syncAndReturn($flow, $companyId),
            DocumentSigningFlowStatus::Blocked => $this->retryBlockedSigningFlow($flow, $actor, $companyId),
        };
    }

    private function retryBlockedSigningFlow(
        DocumentSigningFlow $flow,
        User $actor,
        int $companyId,
    ): DocumentLifecycleAutomation {
        if (! DocumentSigningFlowRetryEligibility::canRetry($flow)) {
            throw ValidationException::withMessages([
                'lifecycle' => 'The linked signing flow cannot be retried from its current state.',
            ]);
        }

        $retried = $this->retrySigningFlow->handle($flow, $actor, $companyId);

        $this->syncFromSigningFlow->handle((int) $retried->id, $companyId);

        /** @var DocumentLifecycleAutomation $lifecycle */
        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->where('document_signing_flow_id', $retried->id)
            ->firstOrFail();

        return $lifecycle;
    }

    private function syncAndReturn(DocumentSigningFlow $flow, int $companyId): DocumentLifecycleAutomation
    {
        $this->syncFromSigningFlow->handle((int) $flow->id, $companyId);

        /** @var DocumentLifecycleAutomation $lifecycle */
        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->where('document_signing_flow_id', $flow->id)
            ->firstOrFail();

        return $lifecycle;
    }

    private function recoverExistingWorkflow(
        DocumentLifecycleAutomation $lifecycle,
        int $companyId,
    ): DocumentLifecycleAutomation {
        $workflowRequest = DocumentWorkflowRequest::query()
            ->forCompany($companyId)
            ->whereKey($lifecycle->document_workflow_request_id)
            ->first();

        if (! $workflowRequest instanceof DocumentWorkflowRequest) {
            throw ValidationException::withMessages([
                'lifecycle' => 'The linked workflow request could not be found for this company.',
            ]);
        }

        return match ($workflowRequest->status) {
            DocumentWorkflowRequestStatus::Pending => $this->markActiveReview($lifecycle, $companyId),
            DocumentWorkflowRequestStatus::Approved => $this->advance->startSnapshottedSigning($lifecycle, $companyId),
            DocumentWorkflowRequestStatus::Rejected => $this->stopAndReturn(
                $lifecycle,
                $companyId,
                DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED,
            ),
            DocumentWorkflowRequestStatus::Cancelled => $this->stopAndReturn(
                $lifecycle,
                $companyId,
                DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_CANCELLED,
            ),
        };
    }

    private function markActiveReview(
        DocumentLifecycleAutomation $lifecycle,
        int $companyId,
    ): DocumentLifecycleAutomation {
        return DB::transaction(function () use ($lifecycle, $companyId): DocumentLifecycleAutomation {
            /** @var DocumentLifecycleAutomation $locked */
            $locked = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->whereKey($lifecycle->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $locked->update([
                'status' => DocumentLifecycleAutomationStatus::Active,
                'stage' => DocumentLifecycleAutomationStage::Review,
                'blocked_code' => null,
                'blocked_message' => null,
                'blocked_at' => null,
                'started_at' => $locked->started_at ?? now(),
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    private function stopAndReturn(
        DocumentLifecycleAutomation $lifecycle,
        int $companyId,
        string $code,
    ): DocumentLifecycleAutomation {
        if ($lifecycle->document_workflow_request_id !== null) {
            $this->stop->handleForWorkflowTerminal(
                (int) $lifecycle->document_workflow_request_id,
                $companyId,
                $code,
            );
        }

        /** @var DocumentLifecycleAutomation $fresh */
        $fresh = DocumentLifecycleAutomation::query()
            ->forCompany($companyId)
            ->whereKey($lifecycle->id)
            ->firstOrFail();

        return $fresh;
    }
}
