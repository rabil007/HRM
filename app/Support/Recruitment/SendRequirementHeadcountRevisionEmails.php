<?php

namespace App\Support\Recruitment;

use App\Jobs\DeliverRequirementHeadcountRevisionEmailJob;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementHeadcountRevision;
use Illuminate\Support\Facades\DB;

final class SendRequirementHeadcountRevisionEmails
{
    public static function requested(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): void {
        self::dispatch(RequirementHeadcountRevisionEmailPayload::forRequested($requirement, $revision));
    }

    public static function approved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): void {
        self::dispatch(RequirementHeadcountRevisionEmailPayload::forApproved($requirement, $revision));
    }

    public static function rejected(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementHeadcountRevision $revision,
    ): void {
        self::dispatch(RequirementHeadcountRevisionEmailPayload::forRejected($requirement, $revision));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function dispatch(array $payload): void
    {
        if (($payload['primary_recipient_user_id'] ?? null) === null) {
            return;
        }

        $dispatch = static function () use ($payload): void {
            DeliverRequirementHeadcountRevisionEmailJob::dispatch($payload);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
