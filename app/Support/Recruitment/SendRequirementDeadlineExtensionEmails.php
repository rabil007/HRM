<?php

namespace App\Support\Recruitment;

use App\Jobs\DeliverRequirementDeadlineExtensionEmailJob;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementDeadlineExtension;
use Illuminate\Support\Facades\DB;

final class SendRequirementDeadlineExtensionEmails
{
    public static function requested(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): void {
        self::dispatch(RequirementDeadlineExtensionEmailPayload::forRequested($requirement, $extension));
    }

    public static function approved(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): void {
        self::dispatch(RequirementDeadlineExtensionEmailPayload::forApproved($requirement, $extension));
    }

    public static function rejected(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): void {
        self::dispatch(RequirementDeadlineExtensionEmailPayload::forRejected($requirement, $extension));
    }

    public static function direct(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementDeadlineExtension $extension,
    ): void {
        self::dispatch(RequirementDeadlineExtensionEmailPayload::forDirect($requirement, $extension));
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
            DeliverRequirementDeadlineExtensionEmailJob::dispatch($payload);
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
