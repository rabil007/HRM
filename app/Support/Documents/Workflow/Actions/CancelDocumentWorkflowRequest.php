<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Exceptions\DocumentWorkflowException;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowAccess;
use App\Support\Documents\Workflow\DocumentWorkflowActivityLogger;
use Illuminate\Support\Facades\DB;

final class CancelDocumentWorkflowRequest
{
    public function __construct(
        private readonly DocumentWorkflowActivityLogger $activityLogger = new DocumentWorkflowActivityLogger,
    ) {}

    public function handle(
        DocumentWorkflowRequest $request,
        User $actor,
        int $companyId,
        ?string $reason = null,
    ): DocumentWorkflowRequest {
        DocumentWorkflowAccess::assertRequestInCompany($request, $companyId);

        return DB::transaction(function () use ($request, $actor, $companyId, $reason): DocumentWorkflowRequest {
            $request = DocumentWorkflowRequest::query()
                ->whereKey($request->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->status !== DocumentWorkflowRequestStatus::Pending) {
                throw DocumentWorkflowException::make(
                    'Only pending workflow requests can be cancelled.',
                    'request_not_cancellable',
                );
            }

            DocumentWorkflowStage::query()
                ->where('document_workflow_request_id', $request->id)
                ->whereIn('status', [
                    DocumentWorkflowStageStatus::Pending,
                    DocumentWorkflowStageStatus::Active,
                ])
                ->lockForUpdate()
                ->get()
                ->each(function (DocumentWorkflowStage $stage): void {
                    $stage->fill([
                        'status' => DocumentWorkflowStageStatus::Cancelled,
                        'completed_at' => now(),
                    ]);
                    $stage->save();
                });

            DocumentWorkflowTask::query()
                ->where('company_id', $companyId)
                ->whereIn('document_workflow_stage_id', function ($query) use ($request): void {
                    $query->select('id')
                        ->from('document_workflow_stages')
                        ->where('document_workflow_request_id', $request->id);
                })
                ->where('status', DocumentWorkflowTaskStatus::Pending)
                ->lockForUpdate()
                ->update(['status' => DocumentWorkflowTaskStatus::Cancelled]);

            $normalizedReason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            $request->fill([
                'status' => DocumentWorkflowRequestStatus::Cancelled,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_reason' => $normalizedReason,
                'completed_at' => now(),
            ]);
            $request->save();

            $this->activityLogger->log(
                description: 'Document workflow cancelled',
                event: 'workflow_cancelled',
                request: $request,
                actor: $actor,
                metadata: [
                    'cancel_reason' => $normalizedReason,
                ],
            );

            return $request->fresh(['stages.tasks']) ?? $request;
        });
    }
}
