<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningFlow;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use Illuminate\Support\Facades\DB;

final class SyncDocumentLifecycleFromSigningFlow
{
    public function __construct(
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handle(int $signingFlowId, int $companyId): void
    {
        DB::transaction(function () use ($signingFlowId, $companyId): void {
            /** @var DocumentLifecycleAutomation|null $lifecycle */
            $lifecycle = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->where('document_signing_flow_id', $signingFlowId)
                ->lockForUpdate()
                ->first();

            if (! $lifecycle instanceof DocumentLifecycleAutomation) {
                return;
            }

            $flow = DocumentSigningFlow::query()
                ->forCompany($companyId)
                ->whereKey($signingFlowId)
                ->lockForUpdate()
                ->first();

            if (! $flow instanceof DocumentSigningFlow) {
                return;
            }

            match ($flow->status) {
                DocumentSigningFlowStatus::Completed => $this->markCompleted($lifecycle),
                DocumentSigningFlowStatus::Cancelled => $this->markStopped($lifecycle),
                DocumentSigningFlowStatus::Blocked => $this->markBlockedFromFlow($lifecycle, $flow),
                DocumentSigningFlowStatus::Active => $this->markActiveSigning($lifecycle),
            };
        });
    }

    private function markCompleted(DocumentLifecycleAutomation $lifecycle): void
    {
        if ($lifecycle->status === DocumentLifecycleAutomationStatus::Completed
            && $lifecycle->stage === DocumentLifecycleAutomationStage::Done
        ) {
            return;
        }

        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Completed,
            'stage' => DocumentLifecycleAutomationStage::Done,
            'blocked_code' => null,
            'blocked_message' => null,
            'blocked_at' => null,
            'completed_at' => $lifecycle->completed_at ?? now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle automation completed',
            event: 'document_lifecycle_completed',
            lifecycle: $lifecycle,
        );
    }

    private function markStopped(DocumentLifecycleAutomation $lifecycle): void
    {
        if ($lifecycle->status === DocumentLifecycleAutomationStatus::Stopped) {
            return;
        }

        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Stopped,
            'blocked_code' => DocumentLifecycleAutomationPolicy::STOP_SIGNING_CANCELLED,
            'blocked_message' => null,
            'blocked_at' => null,
            'completed_at' => $lifecycle->completed_at ?? now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle automation stopped',
            event: 'document_lifecycle_stopped',
            lifecycle: $lifecycle,
            metadata: [
                'stop_code' => DocumentLifecycleAutomationPolicy::STOP_SIGNING_CANCELLED,
            ],
        );
    }

    private function markBlockedFromFlow(
        DocumentLifecycleAutomation $lifecycle,
        DocumentSigningFlow $flow,
    ): void {
        $reason = is_string($flow->blocked_reason) && $flow->blocked_reason !== ''
            ? $flow->blocked_reason
            : 'The linked signing flow is blocked.';

        if (
            $lifecycle->status === DocumentLifecycleAutomationStatus::Blocked
            && $lifecycle->stage === DocumentLifecycleAutomationStage::Signing
            && $lifecycle->blocked_message === $reason
        ) {
            return;
        }

        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Blocked,
            'stage' => DocumentLifecycleAutomationStage::Signing,
            'blocked_code' => DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
            'blocked_message' => $reason,
            'blocked_at' => $lifecycle->blocked_at ?? now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle automation blocked',
            event: 'document_lifecycle_blocked',
            lifecycle: $lifecycle,
            metadata: [
                'blocked_code' => $lifecycle->blocked_code,
                'blocked_message' => $lifecycle->blocked_message,
                'document_signing_flow_id' => $flow->id,
            ],
        );
    }

    private function markActiveSigning(DocumentLifecycleAutomation $lifecycle): void
    {
        if (
            $lifecycle->status === DocumentLifecycleAutomationStatus::Active
            && $lifecycle->stage === DocumentLifecycleAutomationStage::Signing
            && $lifecycle->blocked_code === null
        ) {
            return;
        }

        if ($lifecycle->status->isTerminal()) {
            return;
        }

        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Signing,
            'blocked_code' => null,
            'blocked_message' => null,
            'blocked_at' => null,
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle signing resumed',
            event: 'document_lifecycle_signing_started',
            lifecycle: $lifecycle,
        );
    }
}
