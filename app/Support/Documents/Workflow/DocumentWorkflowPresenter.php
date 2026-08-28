<?php

namespace App\Support\Documents\Workflow;

use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;

final class DocumentWorkflowPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function listItem(DocumentWorkflowRequest $request): array
    {
        $document = $request->documentInstance?->employeeDocument;
        $employee = $document?->employee;
        $activeStage = $request->stages
            ->first(fn (DocumentWorkflowStage $stage): bool => $stage->status === DocumentWorkflowStageStatus::Active);

        $assignedTo = $activeStage !== null
            ? $activeStage->tasks
                ->filter(fn (DocumentWorkflowTask $task): bool => $task->status === DocumentWorkflowTaskStatus::Pending)
                ->pluck('assignee_name_snapshot')
                ->values()
                ->all()
            : [];

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'requested_by' => [
                'id' => $request->requested_by,
                'name' => $request->requester_name_snapshot,
            ],
            'document' => [
                'id' => $document?->id,
                'title' => $document?->title ?? $request->documentInstance?->title_snapshot,
                'employee_document_id' => $document?->id,
            ],
            'employee' => [
                'id' => $employee?->id,
                'name' => $employee?->name ?? $request->documentInstance?->employee_name_snapshot,
                'employee_no' => $employee?->employee_no ?? $request->documentInstance?->employee_no_snapshot,
            ],
            'current_stage' => $activeStage !== null ? [
                'id' => $activeStage->id,
                'sequence' => $activeStage->sequence,
                'action' => $activeStage->action->value,
                'action_label' => $activeStage->action->label(),
                'status' => $activeStage->status->value,
            ] : null,
            'assigned_to' => $assignedTo,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(DocumentWorkflowRequest $request, ?User $viewer = null): array
    {
        $request->loadMissing([
            'requester:id,name',
            'documentInstance.currentVersion',
            'documentInstance.employeeDocument.employee',
            'documentInstance.template',
            'documentInstance.versions',
            'stages.tasks.assignee:id,name',
            'cancelledByUser:id,name',
        ]);

        $document = $request->documentInstance?->employeeDocument;
        $instance = $request->documentInstance;

        $viewerTask = null;
        if ($viewer !== null) {
            foreach ($request->stages as $stage) {
                if ($stage->status !== DocumentWorkflowStageStatus::Active) {
                    continue;
                }

                foreach ($stage->tasks as $task) {
                    if ((int) $task->assignee_user_id === (int) $viewer->id
                        && $task->status === DocumentWorkflowTaskStatus::Pending) {
                        $viewerTask = $task;
                        $viewerTask->setRelation('stage', $stage);
                        break 2;
                    }
                }
            }
        }

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'status_label' => $request->status->label(),
            'requested_at' => $request->requested_at?->toIso8601String(),
            'completed_at' => $request->completed_at?->toIso8601String(),
            'cancelled_at' => $request->cancelled_at?->toIso8601String(),
            'cancel_reason' => $request->cancel_reason,
            'requested_by' => [
                'id' => $request->requested_by,
                'name' => $request->requester_name_snapshot,
            ],
            'cancelled_by' => $request->cancelled_by !== null ? [
                'id' => $request->cancelled_by,
                'name' => $request->cancelledByUser?->name,
            ] : null,
            'document' => [
                'id' => $document?->id,
                'title' => $document?->title ?? $instance?->title_snapshot,
                'file_url' => $document !== null
                    ? route('organization.documents.files.preview', ['document' => $document->id])
                    : null,
                'employee_id' => $document?->employee_id ?? $instance?->employee_id,
            ],
            'employee' => [
                'id' => $document?->employee?->id ?? $instance?->employee_id,
                'name' => $document?->employee?->name ?? $instance?->employee_name_snapshot,
                'employee_no' => $document?->employee?->employee_no ?? $instance?->employee_no_snapshot,
            ],
            'provenance' => $instance !== null ? [
                'template_name' => $instance->template_name_snapshot,
                'template_version' => $instance->template_version_number,
                'document_instance_id' => $instance->id,
                'document_instance_version_id' => $request->document_instance_version_id,
                'bound_version' => $request->relationLoaded('documentInstanceVersion')
                    ? $request->documentInstanceVersion?->version
                    : $request->documentInstance?->versions
                        ->firstWhere('id', $request->document_instance_version_id)?->version,
            ] : null,
            'stages' => $request->stages->map(fn (DocumentWorkflowStage $stage): array => [
                'id' => $stage->id,
                'sequence' => $stage->sequence,
                'action' => $stage->action->value,
                'action_label' => $stage->action->label(),
                'completion_rule' => $stage->completion_rule->value,
                'completion_rule_label' => $stage->completion_rule->label(),
                'status' => $stage->status->value,
                'status_label' => $stage->status->label(),
                'started_at' => $stage->started_at?->toIso8601String(),
                'completed_at' => $stage->completed_at?->toIso8601String(),
                'tasks' => $stage->tasks->map(fn (DocumentWorkflowTask $task): array => [
                    'id' => $task->id,
                    'assignee_user_id' => $task->assignee_user_id,
                    'assignee_name' => $task->assignee_name_snapshot,
                    'status' => $task->status->value,
                    'status_label' => $task->status->label(),
                    'decided_by' => $task->decided_by,
                    'decision_actor_name' => $task->decision_actor_name_snapshot,
                    'decided_at' => $task->decided_at?->toIso8601String(),
                    'decision_notes' => $task->decision_notes,
                ])->values()->all(),
            ])->values()->all(),
            'viewer_task' => $viewerTask !== null ? [
                'id' => $viewerTask->id,
                'action' => $viewerTask->stage?->action->value,
                'action_label' => $viewerTask->stage?->action->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function documentShowWorkflowSummary(DocumentInstance $instance): ?array
    {
        $pending = DocumentWorkflowRequest::query()
            ->where('document_instance_id', $instance->id)
            ->where('document_instance_version_id', $instance->current_version_id)
            ->where('status', DocumentWorkflowRequestStatus::Pending)
            ->with(['stages' => fn ($q) => $q->orderBy('sequence')])
            ->first();

        if ($pending === null) {
            return null;
        }

        return [
            'id' => $pending->id,
            'status' => $pending->status->value,
            'status_label' => $pending->status->label(),
            'show_url' => route('organization.documents.requests.show', ['request' => $pending->id]),
        ];
    }
}
