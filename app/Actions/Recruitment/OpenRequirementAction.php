<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class OpenRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        if ($requirement->status !== RequirementStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => "Requirement cannot be opened from {$requirement->status->label()} status.",
            ]);
        }

        $requirement->update([
            'status' => RequirementStatus::Open,
            'opened_at' => now(),
            'updated_by' => $userId,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
            ])
            ->log("Requirement {$requirement->requirement_number} opened.");

        return $requirement;
    }
}
