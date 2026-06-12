<?php

namespace App\Support\Attendance;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Collection;

final class LeaveTypeYearBalance
{
    public function __construct(
        private CalculateLeaveRequestDays $calculateDays,
    ) {}

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     entitled_days: float,
     *     used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     * }>
     */
    public function forEmployee(int $companyId, int $employeeId, int $year): array
    {
        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $usedDays = $this->daysByLeaveType($companyId, $employeeId, $year, 'approved');
        $pendingDays = $this->daysByLeaveType($companyId, $employeeId, $year, 'pending');

        return $leaveTypes
            ->map(function (LeaveType $leaveType) use ($usedDays, $pendingDays) {
                $entitledDays = (float) $leaveType->days_per_year;
                $used = (float) ($usedDays[$leaveType->id] ?? 0);
                $pending = (float) ($pendingDays[$leaveType->id] ?? 0);
                $remaining = max(0, $entitledDays - $used - $pending);

                return [
                    'id' => $leaveType->id,
                    'name' => $leaveType->name,
                    'code' => $leaveType->code,
                    'color' => $leaveType->color,
                    'entitled_days' => $entitledDays,
                    'used_days' => $used,
                    'pending_days' => $pending,
                    'remaining_days' => $remaining,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function daysByLeaveType(int $companyId, int $employeeId, int $year, string $status): array
    {
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";

        /** @var Collection<int, LeaveRequest> $leaveRequests */
        $leaveRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', $status)
            ->where('start_date', '<=', $yearEnd)
            ->where('end_date', '>=', $yearStart)
            ->get(['id', 'leave_type_id', 'start_date', 'end_date']);

        $totals = [];

        foreach ($leaveRequests as $leaveRequest) {
            $start = max($leaveRequest->start_date?->toDateString() ?? $yearStart, $yearStart);
            $end = min($leaveRequest->end_date?->toDateString() ?? $yearEnd, $yearEnd);

            if ($start > $end) {
                continue;
            }

            $days = ($this->calculateDays)($start, $end);
            $leaveTypeId = (int) $leaveRequest->leave_type_id;
            $totals[$leaveTypeId] = ($totals[$leaveTypeId] ?? 0) + $days;
        }

        return $totals;
    }
}
