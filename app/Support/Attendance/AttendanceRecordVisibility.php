<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class AttendanceRecordVisibility
{
    public function canManageAll(?User $user): bool
    {
        return $user?->can('attendance.records.manage') ?? false;
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
     * @param  Builder<AttendanceRecord>  $query
     */
    public function applyIndexScope($query, ?User $user, int $companyId): void
    {
        if ($this->canManageAll($user)) {
            return;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        if ($employeeId === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('employee_id', $employeeId);
    }

    public function canAccess(AttendanceRecord $record, ?User $user, int $companyId): bool
    {
        if ((int) $record->company_id !== $companyId) {
            return false;
        }

        if ($this->canManageAll($user)) {
            return true;
        }

        $employeeId = $this->linkedEmployeeId($user, $companyId);

        return $employeeId !== null && (int) $record->employee_id === $employeeId;
    }

    public function assertCanAccess(AttendanceRecord $record, ?User $user, int $companyId): void
    {
        abort_unless($this->canAccess($record, $user, $companyId), 404);
    }
}
