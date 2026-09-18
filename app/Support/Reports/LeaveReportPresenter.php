<?php

namespace App\Support\Reports;

use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Employees\EmployeeVisibilityScope;
use Carbon\CarbonInterface;

final class LeaveReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(LeaveRequest $leaveRequest, string $timezone, ?User $user = null): array
    {
        $employee = $leaveRequest->employee;
        $companyId = (int) $leaveRequest->company_id;

        return [
            'id' => $leaveRequest->id,
            'employee' => [
                'id' => $employee?->id,
                'employee_no' => $employee?->employee_no,
                'name' => $employee?->name,
                'can_view' => $employee !== null
                    && ($user?->can('employees.view') ?? false)
                    && EmployeeVisibilityScope::canAccess($user, $employee, $companyId),
            ],
            'department' => self::option($employee?->department),
            'branch' => self::option($employee?->branch),
            'leave_type' => self::option($leaveRequest->leaveType),
            'start_date' => $leaveRequest->start_date?->toDateString(),
            'end_date' => $leaveRequest->end_date?->toDateString(),
            'total_days' => $leaveRequest->total_days !== null ? (float) $leaveRequest->total_days : null,
            'status' => (string) $leaveRequest->status,
            'status_label' => self::statusLabel((string) $leaveRequest->status),
            'submitted_at' => self::datetime($leaveRequest->created_at, $timezone),
            'decided_at' => self::datetime($leaveRequest->decided_at, $timezone),
            'decided_by' => $leaveRequest->approver?->name,
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            default => str($status)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function option(?object $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return ['id' => (int) $model->id, 'name' => (string) $model->name];
    }

    private static function datetime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->toIso8601String();
    }
}
