<?php

namespace App\Support\Documents\Integrity;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\Lifecycle\Actions\AdvanceDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StopDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DocumentIntegritySafeRepair
{
    public const REPAIR_TERMINAL_REMINDER = 'terminal_reminder_pointer';

    public const REPAIR_LIFECYCLE_WORKFLOW = 'lifecycle_stale_workflow';

    public const REPAIR_LIFECYCLE_SIGNING = 'lifecycle_stale_signing';

    public function __construct(
        private AdvanceDocumentLifecycleAutomation $advanceLifecycle,
        private StopDocumentLifecycleAutomation $stopLifecycle,
        private SyncDocumentLifecycleFromSigningFlow $syncFromSigningFlow,
        private DocumentIntegrityActivityLogger $activityLogger = new DocumentIntegrityActivityLogger,
    ) {}

    public function repair(DocumentIntegrityIssue $issue): bool
    {
        if (! $issue->repairable) {
            return false;
        }

        $repaired = match ($issue->code) {
            'recipient_terminal_has_reminder' => $this->clearTerminalReminder($issue),
            'lifecycle_stale_workflow_state' => $this->syncStaleWorkflowLifecycle($issue),
            'lifecycle_stale_signing_state' => $this->syncStaleSigningLifecycle($issue),
            default => false,
        };

        if ($repaired) {
            $this->activityLogger->logRepair(
                $issue->companyId,
                $issue->entityType,
                $issue->entityId,
                match ($issue->code) {
                    'recipient_terminal_has_reminder' => self::REPAIR_TERMINAL_REMINDER,
                    'lifecycle_stale_workflow_state' => self::REPAIR_LIFECYCLE_WORKFLOW,
                    'lifecycle_stale_signing_state' => self::REPAIR_LIFECYCLE_SIGNING,
                    default => $issue->code,
                },
            );
        }

        return $repaired;
    }

    private function clearTerminalReminder(DocumentIntegrityIssue $issue): bool
    {
        $updated = DocumentRecipientRequest::query()
            ->where('company_id', $issue->companyId)
            ->whereKey($issue->entityId)
            ->whereIn('status', [
                DocumentRecipientRequestStatus::Completed,
                DocumentRecipientRequestStatus::Expired,
                DocumentRecipientRequestStatus::Cancelled,
                DocumentRecipientRequestStatus::Superseded,
            ])
            ->whereNotNull('next_reminder_at')
            ->update(['next_reminder_at' => null]);

        return $updated > 0;
    }

    private function syncStaleWorkflowLifecycle(DocumentIntegrityIssue $issue): bool
    {
        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($issue->companyId)
            ->whereKey($issue->entityId)
            ->first();

        if (! $lifecycle instanceof DocumentLifecycleAutomation) {
            return false;
        }

        $workflowId = $issue->relatedId ?? ($lifecycle->document_workflow_request_id !== null
            ? (int) $lifecycle->document_workflow_request_id
            : null);

        if ($workflowId === null) {
            return false;
        }

        $workflow = DocumentWorkflowRequest::query()
            ->forCompany($issue->companyId)
            ->whereKey($workflowId)
            ->first();

        if (! $workflow instanceof DocumentWorkflowRequest) {
            return false;
        }

        try {
            match ($workflow->status) {
                DocumentWorkflowRequestStatus::Approved => $this->advanceLifecycle->handleForApprovedWorkflow(
                    (int) $workflow->id,
                    $issue->companyId,
                ),
                DocumentWorkflowRequestStatus::Rejected => $this->stopLifecycle->handleForWorkflowTerminal(
                    (int) $workflow->id,
                    $issue->companyId,
                    DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED,
                ),
                DocumentWorkflowRequestStatus::Cancelled => $this->stopLifecycle->handleForWorkflowTerminal(
                    (int) $workflow->id,
                    $issue->companyId,
                    DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_CANCELLED,
                ),
                DocumentWorkflowRequestStatus::Pending => null,
            };
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Document integrity lifecycle workflow repair failed', [
                'document_lifecycle_automation_id' => $lifecycle->id,
                'company_id' => $issue->companyId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        return true;
    }

    private function syncStaleSigningLifecycle(DocumentIntegrityIssue $issue): bool
    {
        $lifecycle = DocumentLifecycleAutomation::query()
            ->forCompany($issue->companyId)
            ->whereKey($issue->entityId)
            ->first();

        if (! $lifecycle instanceof DocumentLifecycleAutomation) {
            return false;
        }

        $flowId = $issue->relatedId ?? ($lifecycle->document_signing_flow_id !== null
            ? (int) $lifecycle->document_signing_flow_id
            : null);

        if ($flowId === null) {
            return false;
        }

        try {
            $this->syncFromSigningFlow->handle($flowId, $issue->companyId);
        } catch (Throwable $exception) {
            report($exception);
            Log::warning('Document integrity lifecycle signing repair failed', [
                'document_lifecycle_automation_id' => $lifecycle->id,
                'company_id' => $issue->companyId,
                'exception_class' => $exception::class,
            ]);

            return false;
        }

        return true;
    }
}
