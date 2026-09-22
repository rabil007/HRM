<?php

namespace App\Support\Reports;

use App\Enums\LeaveTypeCategory;
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
        $canViewEmployee = $employee !== null
            && ($user?->can('employees.view') ?? false)
            && EmployeeVisibilityScope::canAccess($user, $employee, $companyId);

        $history = LeaveReportApprovalHistory::forRequest($leaveRequest, $timezone, $user);

        return [
            'id' => $leaveRequest->id,
            'employee' => [
                'id' => $employee?->id,
                'employee_no' => $employee?->employee_no,
                'name' => $employee?->name,
                'image' => $canViewEmployee ? $employee?->image : null,
                'can_view' => $canViewEmployee,
            ],
            'department' => self::option($employee?->department),
            'leave_type' => self::leaveTypeOption($leaveRequest->leaveType),
            'start_date' => $leaveRequest->start_date?->toDateString(),
            'end_date' => $leaveRequest->end_date?->toDateString(),
            'total_days' => $leaveRequest->total_days !== null ? (float) $leaveRequest->total_days : null,
            'status' => (string) $leaveRequest->status,
            'status_label' => self::statusLabel((string) $leaveRequest->status),
            'submitted_at' => self::datetime($leaveRequest->created_at, $timezone),
            'decided_at' => self::datetime($leaveRequest->decided_at, $timezone),
            'decided_by' => $leaveRequest->approver?->name,
            'approval_progress' => $history['approval_progress'],
            'approval_chain' => $history['approval_chain'],
            'reassignments' => $history['reassignments'],
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

    /**
     * @return array{id: int, name: string, code: string, color: string|null, category: string, category_label: string}|null
     */
    private static function leaveTypeOption(?object $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $category = $model->category instanceof LeaveTypeCategory
            ? $model->category
            : LeaveTypeCategory::tryFrom((string) ($model->category ?? '')) ?? LeaveTypeCategory::Other;

        return [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'code' => (string) ($model->code ?? ''),
            'color' => $model->color !== null ? (string) $model->color : null,
            'category' => $category->value,
            'category_label' => $category->label(),
        ];
    }

    private static function datetime(?CarbonInterface $value, string $timezone): ?string
    {
        return $value?->copy()->timezone($timezone)->toIso8601String();
    }
}
