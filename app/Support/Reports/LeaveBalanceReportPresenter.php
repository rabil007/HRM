<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\Attendance\Actions\UpdateLeaveBalanceOpening;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;

final class LeaveBalanceReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(LeaveBalance $balance, ?User $user = null): array
    {
        $employee = $balance->employee;
        $companyId = (int) $balance->company_id;
        $businessYear = (int) now(CompanyTimezone::forCompanyId($companyId))->year;
        $canViewEmployee = $employee !== null
            && ($user?->can('employees.view') ?? false)
            && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);
        $leaveType = $balance->leaveType;
        $category = $leaveType?->category instanceof LeaveTypeCategory
            ? $leaveType->category
            : LeaveTypeCategory::tryFrom((string) ($leaveType?->category ?? '')) ?? LeaveTypeCategory::Other;
        $entitled = round((float) $balance->entitled_days, 2);
        $carried = round((float) $balance->carried_days, 2);
        $openingUsed = round((float) $balance->opening_used_days, 2);
        $used = round((float) $balance->used_days, 2);
        $pending = round((float) $balance->pending_days, 2);
        $canUpdateOpening = ($user?->can('reports.leave_balance.update_opening') ?? false)
            && UpdateLeaveBalanceOpening::isEditable($balance, $user, $companyId, $businessYear);

        return [
            'id' => $balance->id,
            'employee' => [
                'id' => $employee?->id,
                'employee_no' => $employee?->employee_no,
                'name' => $employee?->name ?? 'Unavailable employee',
                'image' => $canViewEmployee ? $employee?->image : null,
                'can_view' => $canViewEmployee,
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
            'opening_used_days' => $openingUsed,
            'opening_balance_as_of' => $balance->opening_balance_as_of?->toDateString(),
            'opening_balance_note' => $canUpdateOpening
                ? (filled($balance->opening_balance_note) ? (string) $balance->opening_balance_note : null)
                : null,
            'used_days' => $used,
            'total_used_days' => round($openingUsed + $used, 2),
            'pending_days' => $pending,
            'remaining_days' => round((float) $balance->remaining_days, 2),
            'can_edit_opening' => $canUpdateOpening,
        ];
    }
}
