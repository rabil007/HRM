<?php

namespace App\Support\Payroll;

use App\Models\Employee;

final class OfficePayrollBoardRow
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Employee $employee, int $periodId, EmployeeLeavePeriodSummary $summary): array
    {
        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
                'image' => $employee->image,
            ],
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => $summary->hasLeaveUsage(),
            'leave_usage' => $summary->toLeaveUsageArray(),
            'total_leave_days' => $summary->totalLeaveDays,
            'primary_account' => EmployeePrimaryAccountResource::forEmployee($employee),
        ];
    }
}
