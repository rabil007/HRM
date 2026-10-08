<?php

namespace App\Support\Recruitment;

use App\Models\RecruitmentRequirement;
use App\Models\User;

final class RequirementTargetDateReminderRecipients
{
    /**
     * @return array{to_user_id: int|null, cc_user_ids: list<int>}
     */
    public static function resolve(RecruitmentRequirement $requirement): array
    {
        $requirement->loadMissing([
            'notificationRecipients.user:id,name,email,status,deleted_at',
        ]);

        $ccUsers = [];

        if ($requirement->creator instanceof User) {
            $ccUsers[] = $requirement->creator;
        }

        if (
            $requirement->submitter instanceof User
            && (int) $requirement->submitter->id !== (int) ($requirement->creator?->id ?? 0)
        ) {
            $ccUsers[] = $requirement->submitter;
        }

        foreach ($requirement->notificationRecipients as $recipient) {
            if ($recipient->user instanceof User) {
                $ccUsers[] = $recipient->user;
            }
        }

        $primary = $requirement->assignedRecruiter instanceof User
            ? $requirement->assignedRecruiter
            : null;

        return RequirementNotificationRecipients::resolveUserIds(
            $requirement,
            $primary,
            $ccUsers,
            primaryMustBeEligibleApprover: false,
        );
    }
}
