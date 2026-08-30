<?php

namespace App\Support\Documents\Lifecycle;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\Lifecycle\Actions\AdvanceDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StartDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StopDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crash-recovery only. Immediate after-commit / post-generation start remains primary.
 */
final class ReconcileDocumentLifecycleAutomations
{
    public const BATCH_LIMIT = 100;

    public function __construct(
        private StartDocumentLifecycleAutomation $start,
        private AdvanceDocumentLifecycleAutomation $advance,
        private StopDocumentLifecycleAutomation $stop,
        private SyncDocumentLifecycleFromSigningFlow $syncFromSigningFlow,
    ) {}

    /**
     * @return array{pending_started: int, reviews_synced: int, signings_synced: int, skipped: int}
     */
    public function handle(?int $onlyCompanyId = null): array
    {
        return [
            'pending_started' => $this->reconcilePending($onlyCompanyId),
            'reviews_synced' => $this->reconcileActiveReviews($onlyCompanyId),
            'signings_synced' => $this->reconcileSigning($onlyCompanyId),
            'skipped' => 0,
        ];
    }

    public function reconcilePending(?int $onlyCompanyId = null): int
    {
        $started = 0;

        $ids = DocumentLifecycleAutomation::query()
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
            ->where('status', DocumentLifecycleAutomationStatus::Pending)
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get(['id', 'company_id']);

        foreach ($ids as $row) {
            try {
                $this->start->handle((int) $row->id, (int) $row->company_id);
                $started++;
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Document lifecycle pending reconciliation failed', [
                    'document_lifecycle_automation_id' => $row->id,
                    'company_id' => $row->company_id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $started;
    }

    public function reconcileActiveReviews(?int $onlyCompanyId = null): int
    {
        $synced = 0;

        $rows = DocumentLifecycleAutomation::query()
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
            ->where('status', DocumentLifecycleAutomationStatus::Active)
            ->where('stage', DocumentLifecycleAutomationStage::Review)
            ->whereNotNull('document_workflow_request_id')
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get(['id', 'company_id', 'document_workflow_request_id']);

        foreach ($rows as $row) {
            try {
                $workflow = DocumentWorkflowRequest::query()
                    ->forCompany((int) $row->company_id)
                    ->whereKey($row->document_workflow_request_id)
                    ->first();

                if (! $workflow instanceof DocumentWorkflowRequest) {
                    continue;
                }

                match ($workflow->status) {
                    DocumentWorkflowRequestStatus::Approved => $this->advance->handleForApprovedWorkflow(
                        (int) $workflow->id,
                        (int) $row->company_id,
                    ),
                    DocumentWorkflowRequestStatus::Rejected => $this->stop->handleForWorkflowTerminal(
                        (int) $workflow->id,
                        (int) $row->company_id,
                        DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_REJECTED,
                    ),
                    DocumentWorkflowRequestStatus::Cancelled => $this->stop->handleForWorkflowTerminal(
                        (int) $workflow->id,
                        (int) $row->company_id,
                        DocumentLifecycleAutomationPolicy::STOP_WORKFLOW_CANCELLED,
                    ),
                    DocumentWorkflowRequestStatus::Pending => null,
                };

                if ($workflow->status !== DocumentWorkflowRequestStatus::Pending) {
                    $synced++;
                }
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Document lifecycle review reconciliation failed', [
                    'document_lifecycle_automation_id' => $row->id,
                    'company_id' => $row->company_id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $synced;
    }

    public function reconcileSigning(?int $onlyCompanyId = null): int
    {
        $synced = 0;

        $rows = DocumentLifecycleAutomation::query()
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where('company_id', $onlyCompanyId),
            )
            ->whereIn('status', [
                DocumentLifecycleAutomationStatus::Active,
                DocumentLifecycleAutomationStatus::Blocked,
            ])
            ->where('stage', DocumentLifecycleAutomationStage::Signing)
            ->whereNotNull('document_signing_flow_id')
            ->orderBy('id')
            ->limit(self::BATCH_LIMIT)
            ->get(['id', 'company_id', 'document_signing_flow_id']);

        foreach ($rows as $row) {
            try {
                $this->syncFromSigningFlow->handle(
                    (int) $row->document_signing_flow_id,
                    (int) $row->company_id,
                );
                $synced++;
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('Document lifecycle signing reconciliation failed', [
                    'document_lifecycle_automation_id' => $row->id,
                    'company_id' => $row->company_id,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $synced;
    }
}
