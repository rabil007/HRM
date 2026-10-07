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
        ?array $context = null,
    ): RecruitmentRequirementStatusTransition {
        return RecruitmentRequirementStatusTransition::query()->create([
            'company_id' => (int) $requirement->company_id,
            'recruitment_requirement_id' => (int) $requirement->id,
            'from_status' => $fromStatus?->value,
            'to_status' => $toStatus->value,
            'performed_by' => $performedBy,
            'reason' => $reason,
            'context' => $context,
        ]);
    }

    /**
     * Ensure a Draft Requirement has a visible null→Draft transition without duplicating it.
     */
    public static function ensureDraftCreated(
        RecruitmentRequirement $requirement,
        int $performedBy,
    ): void {
        if ($requirement->status !== RequirementStatus::Draft) {
            return;
        }

        $exists = RecruitmentRequirementStatusTransition::query()
            ->where('company_id', (int) $requirement->company_id)
            ->where('recruitment_requirement_id', (int) $requirement->id)
            ->whereNull('from_status')
            ->where('to_status', RequirementStatus::Draft->value)
            ->exists();

        if ($exists) {
            return;
        }

        self::handle(
            $requirement,
            null,
            RequirementStatus::Draft,
            $performedBy,
        );
    }
}
