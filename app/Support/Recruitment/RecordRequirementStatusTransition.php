<?php

namespace App\Support\Recruitment;

use App\Enums\Recruitment\RequirementStatus;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;

final class RecordRequirementStatusTransition
{
    public static function handle(
        RecruitmentRequirement $requirement,
        ?RequirementStatus $fromStatus,
        RequirementStatus $toStatus,
        int $performedBy,
        ?string $reason = null,
    ): RecruitmentRequirementStatusTransition {
        return RecruitmentRequirementStatusTransition::query()->create([
            'company_id' => (int) $requirement->company_id,
            'recruitment_requirement_id' => (int) $requirement->id,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'performed_by' => $performedBy,
            'reason' => $reason,
        ]);
    }
}
