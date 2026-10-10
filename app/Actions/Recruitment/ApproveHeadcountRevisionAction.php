<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\CandidateStage;
use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Models\RecruitmentRequirementLine;
use App\Models\User;
use App\Support\Recruitment\RequirementWorkflowAuthorization;
use App\Support\Recruitment\SendRequirementHeadcountRevisionEmails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveHeadcountRevisionAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
        User $actor,
    ): RecruitmentRequirement {
        return DB::transaction(function () use ($requirement, $revision, $actor): RecruitmentRequirement {
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
                    'status' => $lockedRevision->status === RequirementHeadcountRevisionStatus::Approved
                        ? 'This headcount revision has already been approved.'
                        : 'This headcount revision can no longer be approved.',
                ]);
            }

            if (! in_array($locked->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
                throw ValidationException::withMessages([
                    'status' => "Headcount cannot be revised for {$locked->status->label()} requirement.",
                ]);
            }

            $lines = $lockedRevision->lines()->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'status' => 'This headcount revision does not contain any changes.',
                ]);
            }

            $updates = [];

            foreach ($lines as $revisionLine) {
                if ($revisionLine->recruitment_requirement_line_id === null) {
                    throw ValidationException::withMessages([
                        'status' => 'This headcount revision can no longer be approved because the official headcount changed after the request was created.',
                    ]);
                }

                /** @var RecruitmentRequirementLine|null $line */
                $line = RecruitmentRequirementLine::query()
                    ->where('id', $revisionLine->recruitment_requirement_line_id)
                    ->where('recruitment_requirement_id', $locked->id)
                    ->where('company_id', $locked->company_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    $line === null
                    || (int) $line->required_headcount !== (int) $revisionLine->old_headcount
                    || (int) $line->position_id !== (int) $revisionLine->position_id
                ) {
                    throw ValidationException::withMessages([
                        'status' => 'This headcount revision can no longer be approved because the official headcount changed after the request was created.',
                    ]);
                }

                $confirmedJoinedCount = $line->candidates()
                    ->where('stage', CandidateStage::Joined->value)
                    ->count();

                if ((int) $revisionLine->requested_headcount < $confirmedJoinedCount) {
                    $positionTitle = $line->position?->title ?? "Position #{$line->position_id}";
                    throw ValidationException::withMessages([
                        'status' => "This headcount revision cannot be approved because the requested headcount for {$positionTitle} is below the {$confirmedJoinedCount} confirmed joined candidate(s).",
                    ]);
                }

                $updates[] = [$line, (int) $revisionLine->requested_headcount];
            }

            foreach ($updates as [$line, $requestedHeadcount]) {
                $line->update(['required_headcount' => $requestedHeadcount]);
            }

            $locked->update(['updated_by' => $actor->id]);
            $lockedRevision->update([
                'status' => RequirementHeadcountRevisionStatus::Approved,
                'decided_by' => $actor->id,
                'decided_at' => now(),
            ]);

            activity('recruitment')
                ->causedBy($actor)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'headcount_revision_id' => $lockedRevision->id,
                    'changes' => $lines->map(fn ($line): array => [
                        'position' => $line->position_title,
                        'old_headcount' => (int) $line->old_headcount,
                        'new_headcount' => (int) $line->requested_headcount,
                    ])->all(),
                    'reason' => $lockedRevision->reason,
                    'requested_by' => $lockedRevision->requested_by,
                    'approved_by' => $actor->id,
                    'status' => RequirementHeadcountRevisionStatus::Approved->value,
                ])
                ->log('Headcount revision approved.');

            $fresh = $locked->fresh(['assignedRecruiter', 'notificationRecipients', 'creator']) ?? $locked;
            $freshRevision = $lockedRevision->fresh('lines') ?? $lockedRevision;
            SendRequirementHeadcountRevisionEmails::approved($fresh, $freshRevision);

            return $fresh;
        });
    }
}
