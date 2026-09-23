<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use Illuminate\Validation\ValidationException;

final class ExtendDeadlineAction
{
    public function execute(
        RecruitmentRequirement $requirement,
        int $userId,
        string $newDate,
        string $reason,
    ): RecruitmentRequirement {
        if (! $requirement->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => "Deadline cannot be extended for {$requirement->status->label()} requirement.",
            ]);
        }

        $oldDate = $requirement->required_by_date?->format('Y-m-d');

        $requirement->update([
            'required_by_date' => $newDate,
            'updated_by' => $userId,
        ]);

        activity('recruitment')
            ->causedBy($userId)
            ->performedOn($requirement)
            ->withProperties([
                'company_id' => $requirement->company_id,
                'requirement_number' => $requirement->requirement_number,
                'old_required_by_date' => $oldDate,
                'new_required_by_date' => $newDate,
                'reason' => $reason,
            ])
            ->log("Deadline extended from {$oldDate} to {$newDate}. Reason: {$reason}");

        return $requirement;
    }
}
