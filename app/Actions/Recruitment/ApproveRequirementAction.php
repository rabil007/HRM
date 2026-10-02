<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use App\Support\Recruitment\SendRequirementLifecycleEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, User $actor): RecruitmentRequirement
    {
        if (! $actor->can('recruitment.requirements.approve')) {
            throw ValidationException::withMessages([
                'status' => 'You do not have permission to approve requirements.',
            ]);
        }

        $result = DB::transaction(function () use ($requirement, $actor): array {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RequirementStatus::PendingApproval) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be approved from {$locked->status->label()} status.",
                ]);
            }

            if ($locked->assigned_to === null) {
                throw ValidationException::withMessages([
                    'assigned_to' => 'This requirement has no assigned recruiter.',
                ]);
            }

            if ((int) $locked->assigned_to !== (int) $actor->id) {
                throw ValidationException::withMessages([
                    'status' => 'Only the assigned recruiter can approve this requirement.',
                ]);
            }

            if ((int) $locked->created_by === (int) $locked->assigned_to) {
                throw ValidationException::withMessages([
                    'status' => 'Self-approval is not allowed.',
                ]);
            }

            $fromStatus = $locked->status;
            $now = now();

            $locked->update([
                'status' => RequirementStatus::Open,
                'approved_at' => $now,
                'approved_by' => $actor->id,
                'opened_at' => $now,
                'return_reason' => null,
                'returned_at' => null,
                'returned_by' => null,
                'updated_by' => $actor->id,
            ]);

            $transition = RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Open,
                (int) $actor->id,
            );

            activity('recruitment')
                ->causedBy($actor->id)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'approved_at' => $now->toIso8601String(),
                ])
                ->log("Requirement {$locked->requirement_number} approved and opened.");

            $fresh = $locked->fresh([
                'creator',
                'approver',
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

        SendRequirementLifecycleEmails::approved($requirement, $transition);

        return $requirement;
    }
}
