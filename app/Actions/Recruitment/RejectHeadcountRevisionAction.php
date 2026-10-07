<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\User;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementHeadcountRevisionEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RejectHeadcountRevisionAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
        User $actor,
        ?string $decisionNote = null,
    ): RecruitmentRequirement {
        $note = $decisionNote !== null ? trim($decisionNote) : '';

        return DB::transaction(function () use ($requirement, $revision, $actor, $note): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var RecruitmentRequirementHeadcountRevision $lockedRevision */
            $lockedRevision = RecruitmentRequirementHeadcountRevision::query()
                ->where('id', $revision->id)
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            RequirementWorkflowAuthorization::assertCanDecideHeadcountRevision($actor, $locked, $lockedRevision);

            if ($lockedRevision->status !== RequirementHeadcountRevisionStatus::Pending) {
                throw ValidationException::withMessages([
                    'status' => 'This headcount revision can no longer be rejected.',
                ]);
            }

            $lockedRevision->load('lines');
            $lockedRevision->update([
                'status' => RequirementHeadcountRevisionStatus::Rejected,
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
                    'headcount_revision_id' => $lockedRevision->id,
                    'changes' => $lockedRevision->lines->map(fn ($line): array => [
                        'position' => $line->position_title,
                        'old_headcount' => (int) $line->old_headcount,
                        'requested_headcount' => (int) $line->requested_headcount,
                    ])->all(),
                    'reason' => $lockedRevision->reason,
                    'decision_note' => $note !== '' ? $note : null,
                    'requested_by' => $lockedRevision->requested_by,
                    'rejected_by' => $actor->id,
                    'status' => RequirementHeadcountRevisionStatus::Rejected->value,
                ])
                ->log('Headcount revision rejected. Official headcount remains unchanged.');

            $fresh = $locked->fresh(['assignedRecruiter', 'notificationRecipients', 'creator']) ?? $locked;
            $freshRevision = $lockedRevision->fresh('lines') ?? $lockedRevision;
            SendRequirementHeadcountRevisionEmails::rejected($fresh, $freshRevision);

            return $fresh;
        });
    }
}
