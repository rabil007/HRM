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
use App\Support\Documents\Workflow\DocumentWorkflowLockOrder;
use Illuminate\Support\Facades\DB;

final class RejectDocumentWorkflowTask
{
    public function __construct(
        private readonly AdvanceDocumentWorkflow $advancer = new AdvanceDocumentWorkflow,
        private readonly DocumentWorkflowActivityLogger $activityLogger = new DocumentWorkflowActivityLogger,
    ) {}

    public function handle(
        DocumentWorkflowTask $task,
        User $actor,
        int $companyId,
        string $reason,
    ): DocumentWorkflowRequest {
        DocumentWorkflowAccess::assertTaskInCompany($task, $companyId);
        DocumentWorkflowAccess::assertActorIsAssignee($task, $actor);

        $reason = trim($reason);
        if ($reason === '') {
            throw DocumentWorkflowException::make(
                'A rejection reason is required.',
                'rejection_reason_required',
            );
        }

        return DB::transaction(function () use ($task, $actor, $companyId, $reason): DocumentWorkflowRequest {
            ['request' => $request, 'stage' => $stage, 'task' => $task] = DocumentWorkflowLockOrder::lockDecisionContext(
                (int) $task->id,
                $companyId,
            );

            DocumentWorkflowAccess::assertRequestInCompany($request, $companyId);

            $this->assertCanDecide($request, $stage, $actor);

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
                'status' => DocumentWorkflowTaskStatus::Rejected,
                'decided_by' => $actor->id,
                'decision_actor_name_snapshot' => (string) $actor->name,
                'decided_at' => now(),
                'decision_notes' => $reason,
            ]);
            $task->save();

            $this->activityLogger->log(
                description: 'Document workflow task rejected',
                event: 'task_rejected',
                request: $request,
                actor: $actor,
                metadata: [
                    'stage_id' => $stage->id,
                    'task_id' => $task->id,
                    'action' => $stage->action->value,
                ],
            );

            return $this->advancer->afterTaskRejected($request, $stage, $actor, $reason);
        });
    }

    private function assertCanDecide(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        User $actor,
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
}
