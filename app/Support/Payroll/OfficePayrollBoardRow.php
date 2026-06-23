<?php

namespace App\Support\Payroll;

use App\Models\Employee;

final class OfficePayrollBoardRow
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Employee $employee, int $periodId, ?OfficeAttendanceSummary $summary): array
    {
        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
            'period_id' => $periodId,
            'timesheet' => null,
            'is_filled' => $summary !== null && $summary->recordCount > 0,
            'attendance_summary' => $summary === null ? null : [
                'present_days' => $summary->presentDays,
                'absent_days' => $summary->absentDays,
                'overtime_hours' => $summary->overtimeHours,
                'record_count' => $summary->recordCount,
            ],
        ];
    }
}
