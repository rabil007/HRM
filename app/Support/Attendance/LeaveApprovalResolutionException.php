<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalResolutionReason;
use RuntimeException;

final class LeaveApprovalResolutionException extends RuntimeException
{
    public const SELF_APPROVAL_MESSAGE = "We couldn't find another authorized person to approve your leave. You cannot approve your own request. Please contact your HRM administrator to assign a different approver in Leave Approval Settings.";

    /** @param list<string> $diagnostics */
    public function __construct(
        public readonly LeaveApprovalResolutionReason $reason,
        public readonly array $diagnostics,
    ) {
        parent::__construct(match ($reason) {
            LeaveApprovalResolutionReason::SelfApprovalConflict => self::SELF_APPROVAL_MESSAGE,
            LeaveApprovalResolutionReason::DuplicateRequiredApprover => 'The required approval steps could not be assigned to different authorized people. Please contact your HRM administrator to review Leave Approval Settings and the approval policy.',
            default => 'Your leave approval setup needs attention. Please contact your HRM administrator to review Leave Approval Settings and the approval policy.',
        });
    }

    /** @return array<string, string> */
    public function validationErrors(int $companyId): array
    {
        $errors = [
            'leave_request' => $this->getMessage(),
            'leave_approval_reason' => $this->reason->value,
        ];
        $user = auth()->user();
        $previousTeamId = getPermissionsTeamId();
        setPermissionsTeamId($companyId);

        try {
            // Use a fresh identity so cached roles from another team cannot disclose diagnostics.
            if ($user?->fresh()?->can('attendance.leave-approval-settings.view')) {
                $errors['leave_approval_diagnostics'] = implode("\n", $this->diagnostics);
            }
        } finally {
            setPermissionsTeamId($previousTeamId);
        }

        return $errors;
    }
}
