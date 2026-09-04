<?php

namespace App\Support\Documents\MyTasks;

use App\Enums\DocumentRecipientRequestStatus;
use App\Enums\DocumentRecipientType;
use App\Enums\DocumentWorkflowRequestStatus;
use App\Enums\DocumentWorkflowStageStatus;
use App\Enums\DocumentWorkflowTaskStatus;
use App\Models\DocumentRecipientRequest;
use App\Models\DocumentWorkflowRequest;
use App\Models\User;

final class MyTasksCounter
{
    /**
     * Count actionable pending tasks for the given user in the specified company.
     * Strictly isolates to the user's assigned review tasks and direct recipient action requests.
     */
    public function count(User $user, int $companyId): int
    {
        $workflowCount = DocumentWorkflowRequest::query()
            ->forCompany($companyId)
            ->where('status', DocumentWorkflowRequestStatus::Pending)
            ->whereHas('stages.tasks', function ($taskQuery) use ($user): void {
                $taskQuery
                    ->where('assignee_user_id', $user->id)
                    ->where('status', DocumentWorkflowTaskStatus::Pending)
                    ->whereHas('stage', fn ($stageQuery) => $stageQuery->where('status', DocumentWorkflowStageStatus::Active));
            })
            ->count();

        $recipientCount = DocumentRecipientRequest::query()
            ->forCompany($companyId)
            ->where('recipient_type', DocumentRecipientType::CompanyUser)
            ->where('recipient_user_id', $user->id)
            ->where('status', DocumentRecipientRequestStatus::AwaitingAction)
            ->count();

        return $workflowCount + $recipientCount;
    }
}
