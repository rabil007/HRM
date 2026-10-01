<?php

namespace App\Actions\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\User;

/**
 * Resubmit a returned requirement for approval.
 * Delegates to SubmitRequirementForApprovalAction so validation stays in one place.
 */
final class ResubmitRequirementAction
{
    public function __construct(
        private SubmitRequirementForApprovalAction $submitAction,
    ) {}

    public function execute(RecruitmentRequirement $requirement, User $actor): RecruitmentRequirement
    {
        return $this->submitAction->execute($requirement, $actor);
    }
}
