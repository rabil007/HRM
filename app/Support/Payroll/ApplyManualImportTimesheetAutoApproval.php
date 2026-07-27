<?php

namespace App\Support\Payroll;

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;

final class ApplyManualImportTimesheetAutoApproval
{
    public function shouldAutoApprove(CrewTimesheetSource $source): bool
    {
        return in_array($source, [CrewTimesheetSource::Manual, CrewTimesheetSource::Import], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function approvalAttributes(int $approvedByUserId): array
    {
        return [
            'approval_status' => CrewTimesheetApprovalStatus::Approved,
            'approved_by' => $approvedByUserId,
            'approved_at' => now(),
            'submitted_by' => null,
            'submitted_at' => null,
            'returned_by' => null,
            'returned_at' => null,
            'return_reason' => null,
        ];
    }
}
