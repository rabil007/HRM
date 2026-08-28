<?php

namespace App\Support\Documents\Workflow;

use App\Models\DocumentWorkflowRequest;
use App\Models\DocumentWorkflowStage;
use App\Models\DocumentWorkflowTask;
use App\Models\User;

final class DocumentWorkflowAccess
{
    public static function assertRequestInCompany(DocumentWorkflowRequest $request, int $companyId): void
    {
        abort_unless((int) $request->company_id === $companyId, 404);
    }

    public static function assertStageInCompany(DocumentWorkflowStage $stage, int $companyId): void
    {
        abort_unless((int) $stage->company_id === $companyId, 404);
    }

    public static function assertTaskInCompany(DocumentWorkflowTask $task, int $companyId): void
    {
        abort_unless((int) $task->company_id === $companyId, 404);
    }

    public static function assertActorIsAssignee(DocumentWorkflowTask $task, User $actor): void
    {
        abort_unless(
            $task->assignee_user_id !== null && (int) $task->assignee_user_id === (int) $actor->id,
            403,
        );
    }
}
