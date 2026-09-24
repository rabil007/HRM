<?php

namespace App\Support\Attendance\Data;

/**
 * Presentation context for leave-request actionable / FYI emails.
 *
 * Distinguishes the true department manager (template placeholder) from the
 * currently actionable approval snapshot recipients (email table display).
 */
final class LeaveRequestEmailApprovalContext
{
    /**
     * @param  list<string>  $approvalNames
     */
    public function __construct(
        public readonly ?string $approvalLabel,
        public readonly array $approvalNames,
        public readonly ?string $approvalHelpText,
        public readonly string $managerName,
        public readonly string $approverName,
        public readonly string $approverNames,
    ) {}

    public function hasApprovalDisplay(): bool
    {
        return $this->approvalLabel !== null && $this->approvalNames !== [];
    }
}
