<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class FillRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        if (! in_array($requirement->status, [RequirementStatus::Open, RequirementStatus::OnHold], true)) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be marked as filled from {$requirement->status->label()} status.",
            ]);
        }

        $requirement->update([
            'status' => RequirementStatus::Completed,
            'completed_at' => now(),
            'updated_by' => $userId,
        ]);

        $requirement->lines()->where('status', '!=', RequirementLineStatus::Cancelled)->update([
            'status' => RequirementLineStatus::Filled,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
            ])
            ->log("Requirement {$requirement->requirement_number} marked as Filled/Completed.");

        return $requirement;
    }
}
