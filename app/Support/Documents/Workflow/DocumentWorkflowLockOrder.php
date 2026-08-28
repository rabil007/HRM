<?php

namespace App\Support\Documents\Workflow;

use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;

final class DocumentWorkflowLockOrder
{
    /**
     * @return array{request: DocumentWorkflowRequest, stage: DocumentWorkflowStage, task: DocumentWorkflowTask}
     */
    public static function lockDecisionContext(int $taskId, int $companyId): array
    {
        $taskRef = DocumentWorkflowTask::query()
            ->whereKey($taskId)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $stageRef = DocumentWorkflowStage::query()
            ->whereKey($taskRef->document_workflow_stage_id)
            ->where('company_id', $companyId)
            ->firstOrFail();

        $requestId = (int) $stageRef->document_workflow_request_id;
        $stageId = (int) $stageRef->id;

        $request = DocumentWorkflowRequest::query()
            ->whereKey($requestId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->firstOrFail();

        $stage = DocumentWorkflowStage::query()
            ->whereKey($stageId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->firstOrFail();

        $task = DocumentWorkflowTask::query()
            ->whereKey($taskId)
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->firstOrFail();

        return compact('request', 'stage', 'task');
    }
}
