<?php

namespace App\Support\Documents\Lifecycle;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\Signing\DocumentSigningFlowRetryEligibility;

final class DocumentLifecycleAutomationPresenter
{
    public function __construct(
        private DocumentLifecycleAutomationPolicy $policy = new DocumentLifecycleAutomationPolicy,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     status: string,
     *     status_label: string,
     *     stage: string|null,
     *     stage_label: string|null,
     *     blocked_code: string|null,
     *     blocked_message: string|null,
     *     behavior_summary: string,
     *     workflow_request_id: int|null,
     *     signing_flow_id: int|null,
     *     can_retry: bool,
     *     policy_snapshot: array{
     *         schema_version: int|null,
     *         workflow_preset_id: int|null,
     *         workflow_preset_name: string|null,
     *         signing_preset_id: int|null,
     *         signing_preset_name: string|null
     *     }
     * }|null
     */
    public function forDocumentShow(?DocumentLifecycleAutomation $lifecycle): ?array
    {
        if (! $lifecycle instanceof DocumentLifecycleAutomation) {
            return null;
        }

        $status = $lifecycle->status instanceof DocumentLifecycleAutomationStatus
            ? $lifecycle->status
            : DocumentLifecycleAutomationStatus::from((string) $lifecycle->status);

        $stage = $lifecycle->stage instanceof DocumentLifecycleAutomationStage
            ? $lifecycle->stage
            : (
                is_string($lifecycle->stage) && $lifecycle->stage !== ''
                    ? DocumentLifecycleAutomationStage::tryFrom($lifecycle->stage)
                    : null
            );

        $snapshot = is_array($lifecycle->policy_snapshot) ? $lifecycle->policy_snapshot : [];

        $workflowPresetId = is_numeric($snapshot['workflow_preset_id'] ?? null)
            ? (int) $snapshot['workflow_preset_id']
            : null;
        $signingPresetId = is_numeric($snapshot['signing_preset_id'] ?? null)
            ? (int) $snapshot['signing_preset_id']
            : null;

        return [
            'id' => $lifecycle->id,
            'status' => $status->value,
            'status_label' => $status->label(),
            'stage' => $stage?->value,
            'stage_label' => $stage?->label(),
            'blocked_code' => $lifecycle->blocked_code,
            'blocked_message' => $lifecycle->blocked_message,
            'behavior_summary' => $this->policy->behaviorSummary($workflowPresetId, $signingPresetId),
            'workflow_request_id' => $lifecycle->document_workflow_request_id !== null
                ? (int) $lifecycle->document_workflow_request_id
                : null,
            'signing_flow_id' => $lifecycle->document_signing_flow_id !== null
                ? (int) $lifecycle->document_signing_flow_id
                : null,
            'can_retry' => $this->canRetry($lifecycle, $status),
            'policy_snapshot' => [
                'schema_version' => isset($snapshot['schema_version']) && is_numeric($snapshot['schema_version'])
                    ? (int) $snapshot['schema_version']
                    : null,
                'workflow_preset_id' => $workflowPresetId,
                'workflow_preset_name' => isset($snapshot['workflow_preset_name']) && is_string($snapshot['workflow_preset_name'])
                    ? $snapshot['workflow_preset_name']
                    : null,
                'signing_preset_id' => $signingPresetId,
                'signing_preset_name' => isset($snapshot['signing_preset_name']) && is_string($snapshot['signing_preset_name'])
                    ? $snapshot['signing_preset_name']
                    : null,
            ],
        ];
    }

    public function canRetry(
        DocumentLifecycleAutomation $lifecycle,
        ?DocumentLifecycleAutomationStatus $status = null,
    ): bool {
        $status ??= $lifecycle->status instanceof DocumentLifecycleAutomationStatus
            ? $lifecycle->status
            : DocumentLifecycleAutomationStatus::from((string) $lifecycle->status);

        if ($status !== DocumentLifecycleAutomationStatus::Blocked) {
            return false;
        }

        $companyId = (int) $lifecycle->company_id;

        if ($lifecycle->document_signing_flow_id !== null) {
            $flow = DocumentSigningFlow::query()
                ->forCompany($companyId)
                ->whereKey($lifecycle->document_signing_flow_id)
                ->first();

            if (! $flow instanceof DocumentSigningFlow) {
                return false;
            }

            return match ($flow->status) {
                DocumentSigningFlowStatus::Active,
                DocumentSigningFlowStatus::Completed => true,
                DocumentSigningFlowStatus::Cancelled => false,
                DocumentSigningFlowStatus::Blocked => DocumentSigningFlowRetryEligibility::canRetry($flow),
            };
        }

        if ($lifecycle->document_workflow_request_id !== null) {
            $workflow = DocumentWorkflowRequest::query()
                ->forCompany($companyId)
                ->whereKey($lifecycle->document_workflow_request_id)
                ->first();

            if (! $workflow instanceof DocumentWorkflowRequest) {
                return false;
            }

            return match ($workflow->status) {
                DocumentWorkflowRequestStatus::Pending,
                DocumentWorkflowRequestStatus::Approved => true,
                DocumentWorkflowRequestStatus::Rejected,
                DocumentWorkflowRequestStatus::Cancelled => false,
            };
        }

        return true;
    }
}
