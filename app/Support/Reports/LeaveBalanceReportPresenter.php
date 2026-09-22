<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
use App\Models\LeaveBalance;

final class LeaveBalanceReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(LeaveBalance $balance): array
    {
        $employee = $balance->employee;
        $leaveType = $balance->leaveType;
        $category = $leaveType?->category instanceof LeaveTypeCategory
            ? $leaveType->category
            : LeaveTypeCategory::tryFrom((string) ($leaveType?->category ?? '')) ?? LeaveTypeCategory::Other;
        $entitled = round((float) $balance->entitled_days, 2);
        $carried = round((float) $balance->carried_days, 2);
        $used = round((float) $balance->used_days, 2);
        $pending = round((float) $balance->pending_days, 2);

        return [
            'id' => $balance->id,
            'employee' => [
                'id' => $employee?->id,
                'employee_no' => $employee?->employee_no,
                'name' => $employee?->name ?? 'Unavailable employee',
                'status' => $employee?->status,
                'status_label' => $employee?->status !== null
                    ? LeaveBalanceReportFilters::employeeStatusLabel((string) $employee->status)
                    : null,
            ],
            'department' => $employee?->department !== null
                ? ['id' => (int) $employee->department->id, 'name' => (string) $employee->department->name]
                : null,
            'leave_type' => [
                'id' => $leaveType?->id,
                'name' => $leaveType?->name ?? 'Unavailable leave type',
                'code' => $leaveType?->code,
                'category' => $leaveType !== null ? $category->value : null,
                'category_label' => $leaveType !== null ? $category->label() : null,
            ],
            'year' => (int) $balance->year,
            'base_entitlement' => $entitled,
            'carried_days' => $carried,
            'total_available' => round($entitled + $carried, 2),
            'used_days' => $used,
            'pending_days' => $pending,
            'remaining_days' => round((float) $balance->remaining_days, 2),
        ];
    }
}
