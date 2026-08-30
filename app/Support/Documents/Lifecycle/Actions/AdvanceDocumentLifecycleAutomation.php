<?php

namespace App\Support\Documents\Lifecycle\Actions;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningPresetStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningPreset;
use App\Models\DocumentWorkflowRequest;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationActivityLogger;
use App\Support\Documents\Lifecycle\DocumentLifecycleAutomationPolicy;
use App\Support\Documents\Signing\Actions\StartDocumentSigningFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdvanceDocumentLifecycleAutomation
{
    public function __construct(
        private StartDocumentSigningFlow $startSigningFlow,
        private DocumentLifecycleAutomationActivityLogger $activityLogger = new DocumentLifecycleAutomationActivityLogger,
    ) {}

    public function handleForApprovedWorkflow(int $workflowRequestId, int $companyId): void
    {
        DB::transaction(function () use ($workflowRequestId, $companyId): void {
            /** @var DocumentLifecycleAutomation|null $lifecycle */
            $lifecycle = DocumentLifecycleAutomation::query()
                ->forCompany($companyId)
                ->where('document_workflow_request_id', $workflowRequestId)
                ->lockForUpdate()
                ->first();

            if (! $lifecycle instanceof DocumentLifecycleAutomation) {
                return;
            }

            if (
                $lifecycle->status !== DocumentLifecycleAutomationStatus::Active
                || $lifecycle->stage !== DocumentLifecycleAutomationStage::Review
            ) {
                return;
            }

            $this->advanceFromApprovedReview($lifecycle, $companyId, requireActiveReview: true);
        });
    }

    /**
     * Start snapshotted signing for a lifecycle that already has an approved workflow
     * (approval advance) or is blocked awaiting signing start (retry).
     */
    public function startSnapshottedSigning(
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

            return $this->advanceFromApprovedReview($locked, $companyId, requireActiveReview: false);
        });
    }

    private function advanceFromApprovedReview(
        DocumentLifecycleAutomation $lifecycle,
        int $companyId,
        bool $requireActiveReview,
    ): DocumentLifecycleAutomation {
        if ($requireActiveReview) {
            if (
                $lifecycle->status !== DocumentLifecycleAutomationStatus::Active
                || $lifecycle->stage !== DocumentLifecycleAutomationStage::Review
            ) {
                return $lifecycle;
            }
        } elseif (
            ! in_array($lifecycle->status, [
                DocumentLifecycleAutomationStatus::Active,
                DocumentLifecycleAutomationStatus::Blocked,
            ], true)
        ) {
            return $lifecycle;
        }

        if ($lifecycle->document_signing_flow_id !== null) {
            $lifecycle->update([
                'status' => DocumentLifecycleAutomationStatus::Active,
                'stage' => DocumentLifecycleAutomationStage::Signing,
                'blocked_code' => null,
                'blocked_message' => null,
                'blocked_at' => null,
            ]);

            return $lifecycle->fresh() ?? $lifecycle;
        }

        $workflowRequest = DocumentWorkflowRequest::query()
            ->forCompany($companyId)
            ->whereKey($lifecycle->document_workflow_request_id)
            ->lockForUpdate()
            ->first();

        if (
            ! $workflowRequest instanceof DocumentWorkflowRequest
            || $workflowRequest->status !== DocumentWorkflowRequestStatus::Approved
        ) {
            return $lifecycle;
        }

        if ((int) $workflowRequest->document_instance_version_id !== (int) $lifecycle->source_document_instance_version_id) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED,
                'The approved workflow version no longer matches the lifecycle source version.',
            );
        }

        $instance = DocumentInstance::query()
            ->whereKey($lifecycle->document_instance_id)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->first();

        if (
            ! $instance instanceof DocumentInstance
            || (int) $instance->current_version_id !== (int) $lifecycle->source_document_instance_version_id
        ) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SOURCE_VERSION_CHANGED,
                'The document current version no longer matches the lifecycle source version.',
            );
        }

        $initiator = $lifecycle->initiated_by !== null
            ? User::query()->whereKey($lifecycle->initiated_by)->first()
            : null;

        if (! $lifecycle->hasSigning()) {
            $this->activityLogger->log(
                description: 'Document lifecycle review completed',
                event: 'document_lifecycle_review_completed',
                lifecycle: $lifecycle,
                actor: $initiator instanceof User ? $initiator : null,
            );

            $lifecycle->update([
                'status' => DocumentLifecycleAutomationStatus::Completed,
                'stage' => DocumentLifecycleAutomationStage::Done,
                'blocked_code' => null,
                'blocked_message' => null,
                'blocked_at' => null,
                'completed_at' => now(),
            ]);

            $lifecycle = $lifecycle->fresh() ?? $lifecycle;

            $this->activityLogger->log(
                description: 'Document lifecycle automation completed',
                event: 'document_lifecycle_completed',
                lifecycle: $lifecycle,
                actor: $initiator instanceof User ? $initiator : null,
            );

            return $lifecycle;
        }

        $this->activityLogger->log(
            description: 'Document lifecycle review completed',
            event: 'document_lifecycle_review_completed',
            lifecycle: $lifecycle,
            actor: $initiator instanceof User ? $initiator : null,
        );

        if (! $initiator instanceof User) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_MISSING_INITIATOR,
                'The user who initiated generation could not be resolved for lifecycle automation.',
            );
        }

        $signingPresetId = $lifecycle->snapshottedSigningPresetId();

        if ($signingPresetId === null) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_INACTIVE_SIGNING_PRESET,
                'The snapshotted signing preset is missing.',
            );
        }

        $preset = DocumentSigningPreset::query()
            ->where('company_id', $companyId)
            ->whereKey($signingPresetId)
            ->first();

        if (! $preset instanceof DocumentSigningPreset || $preset->status !== DocumentSigningPresetStatus::Active) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_INACTIVE_SIGNING_PRESET,
                'The snapshotted signing preset is missing or not active.',
            );
        }

        $document = EmployeeDocument::query()
            ->whereKey($instance->employee_document_id)
            ->where('company_id', $companyId)
            ->first();

        if (! $document instanceof EmployeeDocument) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
                'The employee document for this lifecycle could not be found.',
            );
        }

        $document->setRelation('documentInstance', $instance);

        try {
            $result = $this->startSigningFlow->handle(
                $document,
                $initiator,
                $companyId,
                $signingPresetId,
                skipLifecycleGuard: true,
            );
        } catch (ValidationException $exception) {
            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_ROUTING_FAILED,
                $this->safeExceptionMessage($exception),
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->markBlocked(
                $lifecycle,
                DocumentLifecycleAutomationPolicy::BLOCK_SIGNING_START_FAILED,
                $this->safeExceptionMessage($exception),
            );
        }

        $lifecycle->update([
            'document_signing_flow_id' => $result['flow']->id,
            'status' => DocumentLifecycleAutomationStatus::Active,
            'stage' => DocumentLifecycleAutomationStage::Signing,
            'blocked_code' => null,
            'blocked_message' => null,
            'blocked_at' => null,
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle signing started',
            event: 'document_lifecycle_signing_started',
            lifecycle: $lifecycle,
            actor: $initiator,
            metadata: [
                'document_signing_flow_id' => $result['flow']->id,
                'signing_preset_id' => $signingPresetId,
            ],
        );

        return $lifecycle;
    }

    private function markBlocked(
        DocumentLifecycleAutomation $lifecycle,
        string $code,
        string $message,
    ): DocumentLifecycleAutomation {
        $lifecycle->update([
            'status' => DocumentLifecycleAutomationStatus::Blocked,
            'blocked_code' => $code,
            'blocked_message' => $message,
            'blocked_at' => now(),
        ]);

        $lifecycle = $lifecycle->fresh() ?? $lifecycle;

        $this->activityLogger->log(
            description: 'Document lifecycle automation blocked',
            event: 'document_lifecycle_blocked',
            lifecycle: $lifecycle,
            metadata: [
                'blocked_code' => $code,
                'blocked_message' => $message,
            ],
        );

        return $lifecycle;
    }

    private function safeExceptionMessage(\Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            foreach ($exception->errors() as $messages) {
                if (is_array($messages) && isset($messages[0]) && is_string($messages[0]) && $messages[0] !== '') {
                    return $messages[0];
                }
            }
        }

        $message = trim($exception->getMessage());

        if ($message !== '' && ! str_contains(strtolower($message), 'sql') && strlen($message) <= 500) {
            return $message;
        }

        return 'Lifecycle automation could not advance to signing.';
    }
}
