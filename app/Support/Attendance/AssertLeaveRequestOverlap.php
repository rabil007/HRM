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
            // Bound the day so DATETIME-backed SQLite columns compare correctly while
            // remaining index-friendly for MySQL DATE columns.
            ->where('start_date', '<=', $endDate.' 23:59:59')
            ->where('end_date', '>=', $startDate.' 00:00:00')
            ->exists();

        if ($hasOverlap) {
            throw ValidationException::withMessages([
                'start_date' => 'These dates overlap with another pending or approved leave request for this employee.',
            ]);
        }
    }
}
