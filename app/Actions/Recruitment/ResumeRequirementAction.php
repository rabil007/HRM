<?php

namespace App\Actions\Recruitment;

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResumeRequirementAction
{
    public function execute(RecruitmentRequirement $requirement, int $userId): RecruitmentRequirement
    {
        return DB::transaction(function () use ($requirement, $userId): RecruitmentRequirement {
            /** @var RecruitmentRequirement $locked */
            $locked = RecruitmentRequirement::query()
                ->where('id', $requirement->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== RequirementStatus::OnHold) {
                throw ValidationException::withMessages([
                    'status' => "Requirement cannot be resumed from {$locked->status->label()} status.",
                ]);
            }

            $locked->update([
                'status' => RequirementStatus::Open,
                'updated_by' => $userId,
            ]);

            $locked->lines()->where('status', RequirementLineStatus::OnHold)->update([
                'status' => RequirementLineStatus::Open,
            ]);

            activity('recruitment')
                ->causedBy($userId)
                ->performedOn($locked)
                ->withProperties([
                    'company_id' => $locked->company_id,
                    'requirement_number' => $locked->requirement_number,
                ])
                ->log("Requirement {$locked->requirement_number} resumed to Open status.");

            return $locked;
        });
    }
}
