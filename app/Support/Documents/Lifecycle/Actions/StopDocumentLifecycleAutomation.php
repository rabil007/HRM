<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Support\Facades\DB;

final class StopDocumentLifecycleAutomation
{
    public function __construct(
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handleForWorkflowTerminal(int $workflowRequestId, int $companyId, string $code): void
    {
        if (! in_array($code, [
            DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED,
            DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_CANCELLED,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($workflowRequestId, $companyId, $code): void {
            /** @var DocumentLifecycleAutomation|null $lifecycle */
            $lifecycle = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->where('document_workflow_request_id', $workflowRequestId)
                ->lockForUpdate()
                ->first();

            if (! $lifecycle instanceof DocumentLifecycleAutomation) {
                return;
            }

            if ($lifecycle->status->isTerminal()) {
                return;
            }

            if (! in_array($lifecycle->status, [
                DocumentLifecycleAutomationStatus::Active,
                DocumentLifecycleAutomationStatus::Pending,
                DocumentLifecycleAutomationStatus::Blocked,
            ], true)) {
                return;
            }

            $stageAllowsStop = $lifecycle->stage === null
                || $lifecycle->stage === DocumentLifecycleAutomationStage::Review
                || $lifecycle->status === DocumentLifecycleAutomationStatus::Pending;

            if (! $stageAllowsStop) {
                return;
            }

            $lifecycle->update([
                'status' => DocumentLifecycleAutomationStatus::Stopped,
                'blocked_code' => $code,
                'blocked_message' => null,
                'blocked_at' => null,
                'completed_at' => now(),
            ]);

            $lifecycle = $lifecycle->fresh() ?? $lifecycle;

            $this->activityLogger->log(
                description: 'Document lifecycle automation stopped',
                event: 'document_lifecycle_stopped',
                lifecycle: $lifecycle,
                metadata: [
                    'stop_code' => $code,
                ],
            );
        });
    }
}
