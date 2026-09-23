<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class ReopenRequirementAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        string $reason,
        ?string $newRequiredByDate = null,
    ): RecruitmentRequirement {
        if (! in_array($requirement->status, [RequirementStatus::Completed, RequirementStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => 'Only completed or cancelled requirements can be reopened.',
            ]);
        }

        $updateData = [
            'status' => RequirementStatus::Open,
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'updated_by' => $userId,
        ];

        if ($newRequiredByDate !== null) {
            $updateData['required_by_date'] = $newRequiredByDate;
        }

        $requirement->update($updateData);

        $requirement->lines()->whereIn('status', [RequirementLineStatus::Filled, RequirementLineStatus::Cancelled])->update([
            'status' => RequirementLineStatus::Open,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
                'reason' => $reason,
                'new_required_by_date' => $newRequiredByDate,
            ])
            ->log("Requirement {$requirement->requirement_number} reopened. Reason: {$reason}");

        return $requirement;
    }
}
