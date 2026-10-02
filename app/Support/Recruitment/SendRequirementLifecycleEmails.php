<?php

namespace App\Support\Recruitment;

use App\Jobs\DeliverRequirementLifecycleEmailJob;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches lifecycle notification jobs after the requirement transaction commits.
 * Jobs receive an immutable event snapshot; email addresses are never serialized.
 */
final class SendRequirementLifecycleEmails
{
    public static function submittedForApproval(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): void {
        self::dispatch(RequirementLifecycleEmailPayload::forSubmitted($requirement, $transition));
    }

    public static function approved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): void {
        self::dispatch(RequirementLifecycleEmailPayload::forApproved($requirement, $transition));
    }

    public static function returned(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): void {
        self::dispatch(RequirementLifecycleEmailPayload::forReturned($requirement, $transition));
    }

    public static function pendingReassigned(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
    ): void {
        self::dispatch(RequirementLifecycleEmailPayload::forReassigned($requirement, $transition));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function dispatch(array $payload): void
    {
        $dispatch = static function () use ($payload): void {
            DeliverRequirementLifecycleEmailJob::dispatch($payload);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
