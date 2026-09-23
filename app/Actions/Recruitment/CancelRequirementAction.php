<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class CancelRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId, string $reason): RecruitmentRequirement
    {
        if (in_array($requirement->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => "A {$requirement->status->label()} requirement cannot be cancelled.",
            ]);
        }

        $requirement->update([
            'status' => RequirementStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'updated_by' => $userId,
        ]);

        $requirement->lines()->update([
            'status' => RequirementLineStatus::Cancelled,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
                'reason' => $reason,
            ])
            ->log("Requirement {$requirement->requirement_number} cancelled. Reason: {$reason}");

        return $requirement;
    }
}
