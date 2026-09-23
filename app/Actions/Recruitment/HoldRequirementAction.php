<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class HoldRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        if ($requirement->status !== RequirementStatus::Open) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be placed on hold from {$requirement->status->label()} status.",
            ]);
        }

        $requirement->update([
            'status' => RequirementStatus::OnHold,
            'updated_by' => $userId,
        ]);

        $requirement->lines()->where('status', RequirementLineStatus::Open)->update([
            'status' => RequirementLineStatus::OnHold,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
            ])
            ->log("Requirement {$requirement->requirement_number} placed on hold.");

        return $requirement;
    }
}
