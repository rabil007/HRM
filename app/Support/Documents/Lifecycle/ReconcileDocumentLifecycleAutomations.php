<?php

namespace App\Support\Documents\Lifecycle;

use App\Enums\DocumentLifecycleAutomationStage;
use App\Enums\DocumentLifecycleAutomationStatus;
use App\Enums\DocumentSigningFlowStatus;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Models\DocumentLifecycleAutomation;
use App\Models\DocumentSigningFlow;
use App\Models\DocumentWorkflowRequest;
use App\Support\Documents\Lifecycle\Actions\AdvanceDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StartDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\StopDocumentLifecycleAutomation;
use App\Support\Documents\Lifecycle\Actions\SyncDocumentLifecycleFromSigningFlow;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crash-recovery only. Immediate after-commit / post-generation start remains primary.
 *
 * Scheduler batches select actionable mismatches only so healthy / Pending rows
 * cannot starve terminal recovery work.
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
        $lifecycleTable = (new DocumentLifecycleAutomation)->getTable();
        $workflowTable = (new DocumentWorkflowRequest)->getTable();

        $rows = DocumentLifecycleAutomation::query()
            ->select("{$lifecycleTable}.id", "{$lifecycleTable}.company_id", "{$lifecycleTable}.document_workflow_request_id")
            ->join(
                $workflowTable,
                "{$workflowTable}.id",
                '=',
                "{$lifecycleTable}.document_workflow_request_id",
            )
            ->where("{$lifecycleTable}.status", DocumentLifecycleAutomationStatus::Active)
            ->where("{$lifecycleTable}.stage", DocumentLifecycleAutomationStage::Review)
            ->whereNotNull("{$lifecycleTable}.document_workflow_request_id")
            ->whereColumn("{$workflowTable}.company_id", "{$lifecycleTable}.company_id")
            ->whereIn("{$workflowTable}.status", [
                DocumentWorkflowRequestStatus::Approved,
                DocumentWorkflowRequestStatus::Rejected,
                DocumentWorkflowRequestStatus::Cancelled,
            ])
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where("{$lifecycleTable}.company_id", $onlyCompanyId),
            )
            ->orderBy("{$lifecycleTable}.id")
            ->limit(self::BATCH_LIMIT)
            ->get();

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

                if (in_array($workflow->status, [
                    DocumentWorkflowRequestStatus::Approved,
                    DocumentWorkflowRequestStatus::Rejected,
                    DocumentWorkflowRequestStatus::Cancelled,
                ], true)) {
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
        $lifecycleTable = (new DocumentLifecycleAutomation)->getTable();
        $flowTable = (new DocumentSigningFlow)->getTable();

        $rows = DocumentLifecycleAutomation::query()
            ->select("{$lifecycleTable}.id", "{$lifecycleTable}.company_id", "{$lifecycleTable}.document_signing_flow_id")
            ->join(
                $flowTable,
                "{$flowTable}.id",
                '=',
                "{$lifecycleTable}.document_signing_flow_id",
            )
            ->whereIn("{$lifecycleTable}.status", [
                DocumentLifecycleAutomationStatus::Active,
                DocumentLifecycleAutomationStatus::Blocked,
            ])
            ->where("{$lifecycleTable}.stage", DocumentLifecycleAutomationStage::Signing)
            ->whereNotNull("{$lifecycleTable}.document_signing_flow_id")
            ->whereColumn("{$flowTable}.company_id", "{$lifecycleTable}.company_id")
            ->where(function ($query) use ($lifecycleTable, $flowTable): void {
                $query
                    // Terminal flow states always need lifecycle repair while still Active/Blocked.
                    ->whereIn("{$flowTable}.status", [
                        DocumentSigningFlowStatus::Completed,
                        DocumentSigningFlowStatus::Cancelled,
                    ])
                    // Flow Active while lifecycle still Blocked.
                    ->orWhere(function ($mismatch) use ($lifecycleTable, $flowTable): void {
                        $mismatch
                            ->where("{$flowTable}.status", DocumentSigningFlowStatus::Active)
                            ->where("{$lifecycleTable}.status", DocumentLifecycleAutomationStatus::Blocked);
                    })
                    // Flow Blocked while lifecycle still Active.
                    ->orWhere(function ($mismatch) use ($lifecycleTable, $flowTable): void {
                        $mismatch
                            ->where("{$flowTable}.status", DocumentSigningFlowStatus::Blocked)
                            ->where("{$lifecycleTable}.status", DocumentLifecycleAutomationStatus::Active);
                    });
            })
            ->when(
                $onlyCompanyId !== null,
                fn ($query) => $query->where("{$lifecycleTable}.company_id", $onlyCompanyId),
            )
            ->orderBy("{$lifecycleTable}.id")
            ->limit(self::BATCH_LIMIT)
            ->get();

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
