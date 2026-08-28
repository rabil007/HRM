<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowAction;
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

final class CompleteDocumentWorkflowTask
{
    public function __construct(
        private readonly AdvanceDocumentWorkflow $advancer = new AdvanceDocumentWorkflow,
        private readonly DocumentWorkflowActivityLogger $activityLogger = new DocumentWorkflowActivityLogger,
    ) {}

    public function handle(
        DocumentWorkflowTask $task,
        User $actor,
        int $companyId,
        ?string $notes = null,
    ): DocumentWorkflowRequest {
        DocumentWorkflowAccess::assertTaskInCompany($task, $companyId);
        DocumentWorkflowAccess::assertActorIsAssignee($task, $actor);

        return DB::transaction(function () use ($task, $actor, $companyId, $notes): DocumentWorkflowRequest {
            $task = DocumentWorkflowTask::query()
                ->whereKey($task->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $stage = DocumentWorkflowStage::query()
                ->whereKey($task->document_workflow_stage_id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $request = DocumentWorkflowRequest::query()
                ->whereKey($stage->document_workflow_request_id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            DocumentWorkflowAccess::assertRequestInCompany($request, $companyId);

            $this->assertCanDecide($request, $stage, $task, $actor, positive: true);

            if ($task->status !== DocumentWorkflowTaskStatus::Pending) {
                throw DocumentWorkflowException::make(
                    'This task has already been decided.',
                    'task_already_decided',
                );
            }

            if ($stage->status !== DocumentWorkflowStageStatus::Active) {
                throw DocumentWorkflowException::make(
                    'This workflow stage is not active.',
                    'stage_not_active',
                );
            }

            if ($request->status !== DocumentWorkflowRequestStatus::Pending) {
                throw DocumentWorkflowException::make(
                    'This workflow request is no longer pending.',
                    'request_not_pending',
                );
            }

            $task->fill([
                'status' => DocumentWorkflowTaskStatus::Completed,
                'decided_by' => $actor->id,
                'decision_actor_name_snapshot' => (string) $actor->name,
                'decided_at' => now(),
                'decision_notes' => $this->normalizeNotes($notes),
            ]);
            $task->save();

            return $this->advancer->afterTaskCompleted($request, $stage, $actor, $notes);
        });
    }

    private function assertCanDecide(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        DocumentWorkflowTask $task,
        User $actor,
        bool $positive,
    ): void {
        if ((int) $request->requested_by === (int) $actor->id) {
            throw DocumentWorkflowException::make(
                'You cannot review or approve your own workflow request.',
                'self_approval_forbidden',
            );
        }

        if ($stage->action === DocumentWorkflowAction::Review) {
            if (! $actor->can('documents.requests.review')) {
                abort(403);
            }
        }

        if ($stage->action === DocumentWorkflowAction::Approve) {
            if (! $actor->can('documents.requests.approve')) {
                abort(403);
            }
        }
    }

    private function normalizeNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }
}
