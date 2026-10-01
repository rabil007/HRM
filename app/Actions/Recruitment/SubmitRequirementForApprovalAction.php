<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SubmitRequirementForApprovalAction
{
    public function execute(RecruitmentRequirement $requirement, User $actor): RecruitmentRequirement
    {
        if (! $actor->can('recruitment.requirements.submit')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to submit requirements for approval.',
            ]);
        }

        $result = DB::transaction(function () use ($requirement, $actor): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Draft, RequirementStatus::Returned], true)) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be submitted from {$locked->status->label()} status.",
                ]);
            }

            if ($locked->assigned_to === null) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'An assigned recruiter is required before submitting for approval.',
                ]);
            }

            if ((int) $locked->created_by === (int) $locked->assigned_to) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'The requester cannot also be the assigned recruiter. Self-approval is not allowed.',
                ]);
            }

            $fromStatus = $locked->status;
            $now = now();

            $locked->update([
                'status' => RequirementStatus::PendingApproval,
                'submitted_at' => $now,
                'submitted_by' => $actor->id,
                'returned_at' => null,
                'returned_by' => null,
                'return_reason' => null,
                'updated_by' => $actor->id,
            ]);

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::PendingApproval,
                (int) $actor->id,
            );

            activity('recruitment')
                ->causedBy($actor->id)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'from_status' => $fromStatus->value,
                    'to_status' => RequirementStatus::PendingApproval->value,
                ])
                ->log("Requirement {$locked->requirement_number} submitted for approval.");

            return $locked->fresh([
                'assignedRecruiter',
                'creator',
                'client',
                'project',
                'lines.position',
                'notificationRecipients.user',
                'company',
            ]) ?? $locked;
        });

        DB::afterCommit(function () use ($result): void {
            SendRequirementLifecycleEmails::submittedForApproval($result);
        });

        return $result;
    }
}
