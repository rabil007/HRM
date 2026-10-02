<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReturnRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, User $actor, string $reason): RecruitmentRequirement
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'return_reason' => 'A return reason is required.',
            ]);
        }

        if (! $actor->can('recruitment.requirements.approve')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to return requirements.',
            ]);
        }

        $result = DB::transaction(function () use ($requirement, $actor, $reason): array {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RequirementStatus::PendingApproval) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be returned from {$locked->status->label()} status.",
                ]);
            }

            if ($locked->assigned_to === null || (int) $locked->assigned_to !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'status' => 'Only the assigned recruiter can return this requirement.',
                ]);
            }

            $fromStatus = $locked->status;
            $now = now();

            $locked->update([
                'status' => RequirementStatus::Returned,
                'returned_at' => $now,
                'returned_by' => $actor->id,
                'return_reason' => $reason,
                'updated_by' => $actor->id,
            ]);

            $transition = RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Returned,
                (int) $actor->id,
                $reason,
            );

            activity('recruitment')
                ->causedBy($actor->id)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'return_reason' => $reason,
                ])
                ->log("Requirement {$locked->requirement_number} returned for changes.");

            $fresh = $locked->fresh([
                'creator',
                'returner',
                'assignedRecruiter',
                'client',
                'project',
                'lines.position',
                'notificationRecipients.user',
                'company',
            ]) ?? $locked;

            return [$fresh, $transition];
        });

        /** @var RecruitmentRequirement $requirement */
        [$requirement, $transition] = $result;

        SendRequirementLifecycleEmails::returned($requirement, $transition);

        return $requirement;
    }
}
