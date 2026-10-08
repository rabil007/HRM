<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementDeadlineExtensionStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\User;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementDeadlineExtensionEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveDeadlineExtensionAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
        User $actor,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $extension, $actor): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var RecruitmentRequirementDeadlineExtension $lockedExtension */
            $lockedExtension = RecruitmentRequirementDeadlineExtension::query()
                ->where('id', $extension->id)
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanDecideDeadlineExtension($actor, $locked, $lockedExtension);

            if ($lockedExtension->status !== RequirementDeadlineExtensionStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => $lockedExtension->status === RequirementDeadlineExtensionStatus::Approved
                        ? 'This deadline extension has already been approved.'
                        : 'This deadline extension can no longer be approved.',
                ]);
            }

            if (! $locked->status->allowsAuditedAdjustments()) {
                throw ValidationException::withMessages([
                    'status' => "Deadline cannot be extended for {$locked->status->label()} requirement.",
                ]);
            }

            $currentDeadline = $locked->required_by_date?->format('Y-m-d');
            $expectedDeadline = $lockedExtension->old_deadline?->format('Y-m-d');

            if ($currentDeadline === null || $expectedDeadline === null || $currentDeadline !== $expectedDeadline) {
                throw ValidationException::withMessages([
                    'status' => 'The current deadline has changed since this request was created. Approve is no longer valid.',
                ]);
            }

            $newDate = $lockedExtension->requested_deadline?->format('Y-m-d');
            if ($newDate === null) {
                throw ValidationException::withMessages([
                    'new_date' => 'This extension request does not have a valid requested deadline.',
                ]);
            }

            $locked->update([
                'required_by_date' => $newDate,
                'updated_by' => $actor->id,
            ]);

            $lockedExtension->update([
                'status' => RequirementDeadlineExtensionStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'deadline_extension_id' => $lockedExtension->id,
                    'old_required_by_date' => $expectedDeadline,
                    'new_required_by_date' => $newDate,
                    'reason' => $lockedExtension->reason,
                    'requested_by' => $lockedExtension->requested_by,
                    'approved_by' => $actor->id,
                    'status' => RequirementDeadlineExtensionStatus::Approved->value,
                ])
                ->log("Deadline extension approved from {$expectedDeadline} to {$newDate}.");

            $fresh = $locked->fresh(['assignedRecruiter', 'notificationRecipients', 'creator']) ?? $locked;
            $freshExtension = $lockedExtension->fresh() ?? $lockedExtension;
            SendRequirementDeadlineExtensionEmails::approved($fresh, $freshExtension);

            return $fresh;
        });
    }
}
