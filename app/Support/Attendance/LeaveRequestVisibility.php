<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class LeaveRequestVisibility
{
    public function canViewAll(?User $user): bool
    {
        return $user?->can('attendance.leave-requests.approve') ?? false;
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

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        if ($employeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('employee_id', $employeeId);
    }

    public function canAccess(LeaveRequest $leaveRequest, ?User $user, int $companyId): bool
    {
        if ((int) $leaveRequest->company_id !== $companyId) {
            return false;
        }

        if ($this->canViewAll($user)) {
            return true;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        return $employeeId !== null && (int) $leaveRequest->employee_id === $employeeId;
    }

    public function assertCanAccess(LeaveRequest $leaveRequest, ?User $user, int $companyId): void
    {
        abort_unless($this->canAccess($leaveRequest, $user, $companyId), 404);
    }
}
