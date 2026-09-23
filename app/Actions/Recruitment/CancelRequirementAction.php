<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
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

            $locked->update([
                'status' => RequirementStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'updated_by' => $userId,
            ]);

            $locked->lines()->update([
                'status' => RequirementLineStatus::Cancelled,
            ]);

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
