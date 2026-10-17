<?php

namespace App\Enums;

enum LeaveApprovalResolutionReason: string
{
    case SelfApprovalConflict = 'self_approval_conflict';
    case DuplicateRequiredApprover = 'duplicate_required_approver';
    case InvalidDesignatedApprover = 'invalid_designated_approver';
    case MissingRequiredApprover = 'missing_required_approver';
    case InvalidPolicy = 'invalid_policy';
}
