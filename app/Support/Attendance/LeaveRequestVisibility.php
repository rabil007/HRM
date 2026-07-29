<?php

namespace App\Support\Attendance;

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class LeaveRequestVisibility
{
    public function canViewAll(?User $user): bool
    {
        return $user?->can('attendance.leave-requests.view_all') ?? false;
    }

    public function linkedEmployeeId(?User $user, int $companyId): ?int
    {
        if ($user === null) {
            return null;
        }

        $employeeId = Employee::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->value('id');

        return $employeeId !== null ? (int) $employeeId : null;
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function applyIndexScope(Builder $query, ?User $user, int $companyId): void
    {
        if ($this->canViewAll($user)) {
            return;
        }

        if ($user === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        $query->where(function (Builder $scoped) use ($employeeId, $user, $companyId): void {
            if ($employeeId !== null) {
                $scoped->where('employee_id', $employeeId)
                    ->orWhereHas('approvals', function (Builder $approvals) use ($user, $companyId): void {
                        $approvals
                            ->where('company_id', $companyId)
                            ->where('approver_user_id', $user->id);
                    });

                return;
            }

            $scoped->whereHas('approvals', function (Builder $approvals) use ($user, $companyId): void {
                $approvals
                    ->where('company_id', $companyId)
                    ->where('approver_user_id', $user->id);
            });
        });
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    public function applyAwaitingMyApprovalScope(Builder $query, User $user, int $companyId): void
    {
        $query
            ->where('company_id', $companyId)
            ->where('status', 'pending')
            ->whereHas('approvals', function (Builder $approvals) use ($user, $companyId): void {
                $approvals
                    ->where('company_id', $companyId)
                    ->where('approver_user_id', $user->id)
                    ->where('status', LeaveRequestApprovalStatus::Pending);
            });
    }

    public function canAccess(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if ((int) $leaveRequest->company_id !== $companyId) {
            return false;
        }

        if ($this->canViewAll($user)) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        if ($employeeId !== null && (int) $leaveRequest->employee_id === $employeeId) {
            return true;
        }

        return LeaveRequestApproval::query()
            ->where('company_id', $companyId)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('approver_user_id', $user->id)
            ->exists();
    }

    public function canApproveCurrentStep(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if ($user === null || (int) $leaveRequest->company_id !== $companyId) {
            return false;
        }

        if ($leaveRequest->status !== 'pending') {
            return false;
        }

        if (! $user->can('attendance.leave-requests.approve')) {
            return false;
        }

        return LeaveRequestApproval::query()
            ->where('company_id', $companyId)
            ->where('leave_request_id', $leaveRequest->id)
            ->where('approver_user_id', $user->id)
            ->where('status', LeaveRequestApprovalStatus::Pending)
            ->exists();
    }

    public function assertCanAccess(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        abort_unless($this->canAccess($leaveRequest, $user, $companyId), 404);
    }

    public function resolveCalendarEmployeeId(Request $request, ?User $user, int $companyId): ?int
    {
        $linkedEmployeeId = $this->linkedEmployeeId($user, $companyId);
        $requestedEmployeeId = trim((string) $request->query('employee_id', ''));

        if (! $this->canViewAll($user)) {
            return $linkedEmployeeId;
        }

        if ($requestedEmployeeId === '') {
            return $linkedEmployeeId;
        }

        $employeeId = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey((int) $requestedEmployeeId)
            ->value('id');

        return $employeeId !== null ? (int) $employeeId : $linkedEmployeeId;
    }
}
