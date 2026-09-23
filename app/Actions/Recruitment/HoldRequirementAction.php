<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HoldRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        return DB::transaction(function () use ($requirement, $userId): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RequirementStatus::Open) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be placed on hold from {$locked->status->label()} status.",
                ]);
            }

            $locked->update([
                'status' => RequirementStatus::OnHold,
                'updated_by' => $userId,
            ]);

            $locked->lines()->where('status', RequirementLineStatus::Open)->update([
                'status' => RequirementLineStatus::OnHold,
            ]);

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                ])
                ->log("Requirement {$locked->requirement_number} placed on hold.");

            return $locked;
        });
    }
}
