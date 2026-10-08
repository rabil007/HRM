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

final class RejectDeadlineExtensionAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
        User $actor,
        ?string $decisionNote = null,
    ): RecruitmentRequirement {
        $note = $decisionNote !== null ? trim($decisionNote) : '';

        return DB::transaction(function () use ($requirement, $extension, $actor, $note): RecruitmentRequirement {
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
                    'status' => 'This deadline extension can no longer be rejected.',
                ]);
            }

            $currentDeadline = $locked->required_by_date?->format('Y-m-d');
            $requestedDeadline = $lockedExtension->requested_deadline?->format('Y-m-d');

            $lockedExtension->update([
                'status' => RequirementDeadlineExtensionStatus::Rejected,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $note !== '' ? $note : null,
            ]);

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'deadline_extension_id' => $lockedExtension->id,
                    'old_required_by_date' => $lockedExtension->old_deadline?->format('Y-m-d'),
                    'requested_deadline' => $requestedDeadline,
                    'current_deadline' => $currentDeadline,
                    'reason' => $lockedExtension->reason,
                    'decision_note' => $note !== '' ? $note : null,
                    'requested_by' => $lockedExtension->requested_by,
                    'rejected_by' => $actor->id,
                    'status' => RequirementDeadlineExtensionStatus::Rejected->value,
                ])
                ->log("Deadline extension rejected. Current deadline remains {$currentDeadline}.");

            $fresh = $locked->fresh(['assignedRecruiter', 'notificationRecipients', 'creator']) ?? $locked;
            $freshExtension = $lockedExtension->fresh() ?? $lockedExtension;
            SendRequirementDeadlineExtensionEmails::rejected($fresh, $freshExtension);

            return $fresh;
        });
    }
}
