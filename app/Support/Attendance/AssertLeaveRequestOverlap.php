<?php

namespace App\Support\Attendance;

use App\Models\LeaveRequest;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative leave-request date-overlap check for domain workflows.
 */
final class AssertLeaveRequestOverlap
{
    /**
     * @throws ValidationException
     */
    public function handle(
        int $companyId,
        int $employeeId,
        string $startDate,
        string $endDate,
        ?int $excludeLeaveRequestId = null,
    ): void {
        $hasOverlap = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->when(
                $excludeLeaveRequestId !== null,
                fn ($query) => $query->whereKeyNot($excludeLeaveRequestId),
            )
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'start_date' => 'These dates overlap with another pending or approved leave request for this employee.',
            ]);
        }
    }
}
