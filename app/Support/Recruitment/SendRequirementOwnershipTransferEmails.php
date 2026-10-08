<?php

namespace App\Support\Recruitment;

use App\Jobs\DeliverRequirementOwnershipTransferEmailJob;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementStatusTransition;
use Illuminate\Support\Facades\DB;

final class SendRequirementOwnershipTransferEmails
{
    /**
     * @param  list<array{user_id: int, role: 'requester'|'recruiter'}>  $recipients
     */
    public static function notify(
        RecruitmentRequirement $requirement,
        RecruitmentRequirementStatusTransition $transition,
        array $recipients,
    ): void {
        foreach ($recipients as $recipient) {
            $userId = (int) ($recipient['user_id'] ?? 0);
            $role = (string) ($recipient['role'] ?? '');

            if ($userId <= 0 || ! in_array($role, ['requester', 'recruiter'], true)) {
                continue;
            }

            $payload = [
                'requirement_id' => (int) $requirement->id,
                'company_id' => (int) $requirement->company_id,
                'status_transition_id' => (int) $transition->id,
                'primary_recipient_user_id' => $userId,
                'role' => $role,
                'expected_requester_id' => $requirement->created_by !== null
                    ? (int) $requirement->created_by
                    : null,
                'expected_recruiter_id' => $requirement->assigned_to !== null
                    ? (int) $requirement->assigned_to
                    : null,
                'expected_status' => $requirement->status->value,
            ];

            $dispatch = static function () use ($payload): void {
                DeliverRequirementOwnershipTransferEmailJob::dispatch($payload);
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($dispatch);
            } else {
                $dispatch();
            }
        }
    }
}
