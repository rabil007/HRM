<?php

namespace App\Support\Attendance;

use App\Models\LeaveRequestApproval;
use Illuminate\Support\Collection;

/**
 * Shared duplicate-approver semantics for leave approval snapshots.
 * Matches AssertLeaveApprovalWorkflowInvariant: any step with an
 * approver_employee_id counts, including FYI / optional rows.
 */
final class LeaveApprovalApproverDuplicates
{
    /**
     * @param  Collection<int, LeaveRequestApproval>|iterable<int, LeaveRequestApproval>  $approvals
     * @return list<int>
     */
    public static function employeeIds(iterable $approvals, ?int $exceptApprovalId = null): array
    {
        $ids = [];

        foreach ($approvals as $approval) {
            if ($exceptApprovalId !== null && (int) $approval->id === $exceptApprovalId) {
                continue;
            }

            if ($approval->approver_employee_id === null) {
                continue;
            }

            $ids[] = (int) $approval->approver_employee_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  Collection<int, LeaveRequestApproval>|iterable<int, LeaveRequestApproval>  $approvals
     */
    public static function containsEmployee(
        iterable $approvals,
        int $employeeId,
        ?int $exceptApprovalId = null,
    ): bool {
        return in_array($employeeId, self::employeeIds($approvals, $exceptApprovalId), true);
    }
}
