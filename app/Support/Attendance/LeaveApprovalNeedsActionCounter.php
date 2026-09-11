<?php

namespace App\Support\Attendance;

use App\Models\LeaveRequest;
use App\Models\User;

/**
 * Counts leave requests that currently need the actor's approval decision.
 *
 * Matches Attendance → Approvals → "Needs action" (`awaiting_my_approval`).
 * Does not include historical `assigned_to_me` assignments.
 */
final class LeaveApprovalNeedsActionCounter
{
    public function __construct(
        private LeaveRequestVisibility $visibility,
    ) {}

    public function count(User $user, int $companyId): int
    {
        if (
            ! $user->can('attendance.leave-requests.view')
            || ! $user->can('attendance.leave-requests.approve')
        ) {
            return 0;
        }

        $query = LeaveRequest::query();
        $this->visibility->applyAwaitingMyApprovalScope($query, $user, $companyId);

        return (int) $query->count();
    }
}
