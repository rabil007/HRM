<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
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

            $locked->update([
                'status' => RequirementStatus::Completed,
                'completed_at' => now(),
                'updated_by' => $userId,
            ]);

            $locked->lines()->where('status', '!=', RequirementLineStatus::Cancelled)->update([
                'status' => RequirementLineStatus::Filled,
            ]);

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
