<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Exceptions\DocumentWorkflowException;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowActivityLogger;
use Illuminate\Support\Facades\DB;

final class AdvanceDocumentWorkflow
{
    public function __construct(
        private readonly DocumentWorkflowActivityLogger $activityLogger = new DocumentWorkflowActivityLogger,
    ) {}

    public function afterTaskCompleted(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        User $actor,
        ?string $notes = null,
    ): DocumentWorkflowRequest {
        return DB::transaction(function () use ($request, $stage, $actor, $notes): DocumentWorkflowRequest {
            $request = $this->lockRequest($request);
            $stage = $this->lockStage($stage);

            if (! $this->isStageReadyToComplete($stage)) {
                return $request->fresh(['stages.tasks']) ?? $request;
            }

            return $this->completeStageAndAdvance($request, $stage, $actor, $notes);
        });
    }

    public function afterTaskRejected(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        User $actor,
        ?string $notes = null,
    ): DocumentWorkflowRequest {
        return DB::transaction(function () use ($request, $stage, $actor, $notes): DocumentWorkflowRequest {
            $request = $this->lockRequest($request);
            $stage = $this->lockStage($stage);

            if ($stage->completion_rule === DocumentWorkflowCompletionRule::All) {
                return $this->rejectRequest($request, $stage, $actor, $notes);
            }

            if (! $this->allTasksRejected($stage)) {
                return $request->fresh(['stages.tasks']) ?? $request;
            }

            return $this->rejectRequest($request, $stage, $actor, $notes);
        });
    }

    private function completeStageAndAdvance(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        User $actor,
        ?string $notes,
    ): DocumentWorkflowRequest {
        if ($stage->completion_rule === DocumentWorkflowCompletionRule::Any) {
            $stage->tasks()
                ->where('status', DocumentWorkflowTaskStatus::Pending)
                ->update(['status' => DocumentWorkflowTaskStatus::Skipped]);
        }

        $stage->fill([
            'status' => DocumentWorkflowStageStatus::Completed,
            'completed_at' => now(),
        ]);
        $stage->save();

        $this->activityLogger->log(
            description: 'Document workflow stage completed',
            event: 'stage_completed',
            request: $request,
            actor: $actor,
            metadata: [
                'stage_id' => $stage->id,
                'action' => $stage->action->value,
                'sequence' => $stage->sequence,
            ],
        );

        if ($stage->action === DocumentWorkflowAction::Review) {
            $this->activityLogger->log(
                description: 'Document workflow review completed',
                event: 'review_completed',
                request: $request,
                actor: $actor,
                metadata: [
                    'stage_id' => $stage->id,
                    'task_notes' => $notes,
                ],
            );
        }

        if ($stage->action === DocumentWorkflowAction::Approve) {
            $this->activityLogger->log(
                description: 'Document workflow approval completed',
                event: 'approval_completed',
                request: $request,
                actor: $actor,
                metadata: [
                    'stage_id' => $stage->id,
                    'task_notes' => $notes,
                ],
            );
        }

        $nextStage = DocumentWorkflowStage::query()
            ->where('document_workflow_request_id', $request->id)
            ->where('sequence', '>', $stage->sequence)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->first();

        if ($nextStage === null) {
            if ($stage->action !== DocumentWorkflowAction::Approve) {
                throw DocumentWorkflowException::make(
                    'The final workflow stage must be an approval stage.',
                    'invalid_final_stage',
                );
            }

            $request->fill([
                'status' => DocumentWorkflowRequestStatus::Approved,
                'completed_at' => now(),
            ]);
            $request->save();

            $this->activityLogger->log(
                description: 'Document workflow approved',
                event: 'workflow_approved',
                request: $request,
                actor: $actor,
            );

            return $request->fresh(['stages.tasks']) ?? $request;
        }

        $nextStage->fill([
            'status' => DocumentWorkflowStageStatus::Active,
            'started_at' => now(),
        ]);
        $nextStage->save();

        return $request->fresh(['stages.tasks']) ?? $request;
    }

    private function rejectRequest(
        DocumentWorkflowRequest $request,
        DocumentWorkflowStage $stage,
        User $actor,
        ?string $notes,
    ): DocumentWorkflowRequest {
        $stage->fill([
            'status' => DocumentWorkflowStageStatus::Rejected,
            'completed_at' => now(),
        ]);
        $stage->save();

        DocumentWorkflowStage::query()
            ->where('document_workflow_request_id', $request->id)
            ->whereIn('status', [
                DocumentWorkflowStageStatus::Pending,
                DocumentWorkflowStageStatus::Active,
            ])
            ->whereKeyNot($stage->id)
            ->update(['status' => DocumentWorkflowStageStatus::Cancelled]);

        DocumentWorkflowTask::query()
            ->where('company_id', $request->company_id)
            ->whereIn('document_workflow_stage_id', function ($query) use ($request): void {
                $query->select('id')
                    ->from('document_workflow_stages')
                    ->where('document_workflow_request_id', $request->id);
            })
            ->where('status', DocumentWorkflowTaskStatus::Pending)
            ->update(['status' => DocumentWorkflowTaskStatus::Cancelled]);

        $request->fill([
            'status' => DocumentWorkflowRequestStatus::Rejected,
            'completed_at' => now(),
        ]);
        $request->save();

        $this->activityLogger->log(
            description: 'Document workflow rejected',
            event: 'workflow_rejected',
            request: $request,
            actor: $actor,
            metadata: [
                'stage_id' => $stage->id,
                'task_notes' => $notes,
            ],
        );

        return $request->fresh(['stages.tasks']) ?? $request;
    }

    private function isStageReadyToComplete(DocumentWorkflowStage $stage): bool
    {
        $stage->loadMissing('tasks');

        if ($stage->completion_rule === DocumentWorkflowCompletionRule::All) {
            return $stage->tasks->every(
                fn (DocumentWorkflowTask $task): bool => $task->status === DocumentWorkflowTaskStatus::Completed,
            );
        }

        return $stage->tasks->contains(
            fn (DocumentWorkflowTask $task): bool => $task->status === DocumentWorkflowTaskStatus::Completed,
        );
    }

    private function allTasksRejected(DocumentWorkflowStage $stage): bool
    {
        $stage->loadMissing('tasks');

        if ($stage->tasks->isEmpty()) {
            return false;
        }

        return $stage->tasks->every(function (DocumentWorkflowTask $task): bool {
            return in_array($task->status, [
                DocumentWorkflowTaskStatus::Rejected,
                DocumentWorkflowTaskStatus::Skipped,
                DocumentWorkflowTaskStatus::Cancelled,
            ], true);
        }) && $stage->tasks->contains(
            fn (DocumentWorkflowTask $task): bool => $task->status === DocumentWorkflowTaskStatus::Rejected,
        );
    }

    private function lockRequest(DocumentWorkflowRequest $request): DocumentWorkflowRequest
    {
        return DocumentWorkflowRequest::query()
            ->whereKey($request->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockStage(DocumentWorkflowStage $stage): DocumentWorkflowStage
    {
        return DocumentWorkflowStage::query()
            ->whereKey($stage->id)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
