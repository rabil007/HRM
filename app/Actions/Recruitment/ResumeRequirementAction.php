<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class ResumeRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        if ($requirement->status !== RequirementStatus::OnHold) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be resumed from {$requirement->status->label()} status.",
            ]);
        }

        $requirement->update([
            'status' => RequirementStatus::Open,
            'updated_by' => $userId,
        ]);

        $requirement->lines()->where('status', RequirementLineStatus::OnHold)->update([
            'status' => RequirementLineStatus::Open,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
            ])
            ->log("Requirement {$requirement->requirement_number} resumed to Open status.");

        return $requirement;
    }
}
