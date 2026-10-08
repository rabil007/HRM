<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use App\Models\RecruitmentRequirementHeadcountRevision;
use App\Support\Recruitment\RecordRequirementStatusTransition;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FillRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        return DB::transaction(function () use ($requirement, $userId): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be marked as filled from {$locked->status->label()} status.",
                ]);
            }

            $pendingDeadlineExtension = RecruitmentRequirementDeadlineExtension::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            $pendingHeadcountRevision = RecruitmentRequirementHeadcountRevision::query()
                ->where('recruitment_requirement_id', $locked->id)
                ->where('company_id', $locked->company_id)
                ->pending()
                ->lockForUpdate()
                ->exists();

            if ($pendingDeadlineExtension && $pendingHeadcountRevision) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has pending deadline-extension and headcount-revision requests. Approve or reject them before marking the requirement as filled.',
                ]);
            }

            if ($pendingDeadlineExtension) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has a pending deadline extension. Approve or reject the request before marking the requirement as filled.',
                ]);
            }

            if ($pendingHeadcountRevision) {
                throw ValidationException::withMessages([
                    'status' => 'This requirement has a pending headcount revision. Approve or reject the revision before marking the requirement as filled.',
                ]);
            }

            $fromStatus = $locked->status;

            $locked->update([
                'status' => RequirementStatus::Completed,
                'completed_at' => now(),
                'updated_by' => $userId,
            ]);

            $locked->lines()->where('status', '!=', RequirementLineStatus::Cancelled)->update([
                'status' => RequirementLineStatus::Filled,
            ]);

            RecordRequirementStatusTransition::handle(
                $locked,
                $fromStatus,
                RequirementStatus::Completed,
                $userId,
            );

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                ])
                ->log("Requirement {$locked->requirement_number} marked as Filled/Completed.");

            return $locked;
        });
    }
}
