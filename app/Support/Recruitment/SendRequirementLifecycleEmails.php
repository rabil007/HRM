<?php

namespace App\Support\Recruitment;

use App\Jobs\DeliverRequirementLifecycleEmailJob;
use App\Models\RecruitmentRequirement;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches lifecycle notification jobs after the requirement transaction commits.
 * Jobs reload and revalidate recipients; email addresses are never serialized ahead of time.
 */
final class SendRequirementLifecycleEmails
{
    public static function submittedForApproval(RecruitmentRequirement $requirement): void
    {
        self::dispatch($requirement, 'submitted');
    }

    public static function approved(RecruitmentRequirement $requirement): void
    {
        self::dispatch($requirement, 'approved');
    }

    public static function returned(RecruitmentRequirement $requirement): void
    {
        self::dispatch($requirement, 'returned');
    }

    public static function pendingReassigned(RecruitmentRequirement $requirement): void
    {
        self::dispatch($requirement, 'reassigned');
    }

    private static function dispatch(RecruitmentRequirement $requirement, string $event): void
    {
        $requirementId = (int) $requirement->id;
        $companyId = (int) $requirement->company_id;

        $dispatch = static function () use ($requirementId, $companyId, $event): void {
            DeliverRequirementLifecycleEmailJob::dispatch($requirementId, $companyId, $event);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
