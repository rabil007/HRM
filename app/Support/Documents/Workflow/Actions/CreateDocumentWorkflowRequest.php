<?php

namespace App\Support\Documents\Workflow\Actions;

use App\Enums\DocumentWorkflowAction;
use App\Enums\DocumentWorkflowCompletionRule;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentInstance;
use App\Models\DocumentInstanceVersion;
use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\EmployeeDocument;
use App\Models\User;
use App\Support\Documents\Workflow\DocumentWorkflowActivityLogger;
use App\Support\Documents\Workflow\DocumentWorkflowAssigneeValidator;
use App\Support\EmployeeDocuments\DocumentAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateDocumentWorkflowRequest
{
    public function __construct(
        private readonly DocumentWorkflowAssigneeValidator $assigneeValidator = new DocumentWorkflowAssigneeValidator,
        private readonly DocumentWorkflowActivityLogger $activityLogger = new DocumentWorkflowActivityLogger,
    ) {}

    /**
     * @param  list<array{action: string, completion_rule: string, assignee_user_ids: list<int>}>  $stages
     */
    public function handle(
        User $requester,
        int $companyId,
        EmployeeDocument $document,
        array $stages,
    ): DocumentWorkflowRequest {
        DocumentAccess::assertDocumentInCompany($document, $companyId);

        $document->loadMissing('documentInstance.currentVersion', 'employee');

        $instance = $document->documentInstance;
        if ($instance === null) {
            throw ValidationException::withMessages([
                'document' => ['This document does not have generated document provenance.'],
            ]);
        }

        abort_unless((int) $instance->company_id === $companyId, 404);

        $usersById = $this->assigneeValidator->validateStages($companyId, $stages, (int) $requester->id);

        return DB::transaction(function () use ($requester, $companyId, $instance, $stages, $usersById): DocumentWorkflowRequest {
            $lockedInstance = DocumentInstance::query()
                ->whereKey($instance->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInstance->current_version_id === null) {
                throw ValidationException::withMessages([
                    'document' => ['This document does not have a current version.'],
                ]);
            }

            $version = DocumentInstanceVersion::query()
                ->whereKey($lockedInstance->current_version_id)
                ->where('company_id', $companyId)
                ->where('document_instance_id', $lockedInstance->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingPending = DocumentWorkflowRequest::query()
                ->where('company_id', $companyId)
                ->where('document_instance_id', $lockedInstance->id)
                ->where('document_instance_version_id', $version->id)
                ->where('status', DocumentWorkflowRequestStatus::Pending)
                ->lockForUpdate()
                ->exists();

            if ($existingPending) {
                throw ValidationException::withMessages([
                    'document' => ['An active review or approval request already exists for this document version.'],
                ]);
            }

            $request = DocumentWorkflowRequest::query()->create([
                'company_id' => $companyId,
                'document_instance_id' => $lockedInstance->id,
                'document_instance_version_id' => $version->id,
                'status' => DocumentWorkflowRequestStatus::Pending,
                'requested_by' => $requester->id,
                'requester_name_snapshot' => (string) $requester->name,
                'requested_at' => now(),
            ]);

            foreach ($stages as $index => $stageInput) {
                $sequence = $index + 1;
                $isFirst = $sequence === 1;

                $stage = DocumentWorkflowStage::query()->create([
                    'company_id' => $companyId,
                    'document_workflow_request_id' => $request->id,
                    'sequence' => $sequence,
                    'action' => DocumentWorkflowAction::from($stageInput['action']),
                    'completion_rule' => DocumentWorkflowCompletionRule::from($stageInput['completion_rule']),
                    'status' => $isFirst
                        ? DocumentWorkflowStageStatus::Active
                        : DocumentWorkflowStageStatus::Pending,
                    'started_at' => $isFirst ? now() : null,
                ]);

                foreach ($stageInput['assignee_user_ids'] as $assigneeUserId) {
                    $assignee = $usersById[(int) $assigneeUserId];

                    DocumentWorkflowTask::query()->create([
                        'company_id' => $companyId,
                        'document_workflow_stage_id' => $stage->id,
                        'assignee_user_id' => $assignee->id,
                        'assignee_name_snapshot' => (string) $assignee->name,
                        'status' => DocumentWorkflowTaskStatus::Pending,
                    ]);
                }
            }

            $request->load(['stages.tasks', 'documentInstance.employeeDocument.employee']);

            $this->activityLogger->log(
                description: 'Document workflow request created',
                event: 'workflow_created',
                request: $request,
                actor: $requester,
                metadata: [
                    'stage_count' => count($stages),
                ],
            );

            return $request;
        });
    }
}
