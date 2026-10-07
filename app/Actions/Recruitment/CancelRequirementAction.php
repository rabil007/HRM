<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementHeadcountRevisionStatus;
use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CancelRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId, string $reason): RecruitmentRequirement
    {
        return DB::transaction(function () use ($requirement, $userId, $reason): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($locked->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} requirement cannot be cancelled.",
                ]);
            }

            $fromStatus = $locked->status;

            /** @var RecruitmentRequirementHeadcountRevision|null $pendingHeadcountRevision */
            $pendingHeadcountRevision = RecruitmentRequirementHeadcountRevision::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->first();

            if ($pendingHeadcountRevision !== null) {
                $pendingHeadcountRevision->load('lines');
                $cancellationNote = 'Cancelled because the requirement was cancelled.';
                $pendingHeadcountRevision->update([
                    'status' => RequirementHeadcountRevisionStatus::Cancelled,
                    'decided_by' => $userId,
                    'decided_at' => now(),
                    'decision_note' => $cancellationNote,
                ]);

                activity('recruitment')
                    ->causedBy($userId)
                    ->performedOn($locked)
                    ->withProperties([
                        'company_id' => $locked->company_id,
                        'requirement_number' => $locked->requirement_number,
                        'headcount_revision_id' => $pendingHeadcountRevision->id,
                        'changes' => $pendingHeadcountRevision->lines->map(fn ($line): array => [
                            'position' => $line->position_title,
                            'old_headcount' => (int) $line->old_headcount,
                            'requested_headcount' => (int) $line->requested_headcount,
                        ])->all(),
                        'reason' => $pendingHeadcountRevision->reason,
                        'decision_note' => $cancellationNote,
                        'status' => RequirementHeadcountRevisionStatus::Cancelled->value,
                    ])
                    ->log('Headcount revision cancelled. Official headcount remains unchanged.');
            }

            $locked->update([
                'status' => RequirementStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $userId,
            ]);

            $locked->lines()->update([
                'status' => RequirementLineStatus::Cancelled,
            ]);

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Cancelled,
                $userId,
                $reason,
            );

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                    'reason' => $reason,
                ])
                ->log("Requirement {$locked->requirement_number} cancelled. Reason: {$reason}");

            return $locked;
        });
    }
}
