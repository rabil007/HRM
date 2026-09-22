<?php

namespace App\Support\Attendance\Actions;

use App\Models\Department;
use App\Support\Attendance\DepartmentAttendanceLeaveGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Transaction-authoritative department participation / deletion mutations.
 *
 * FormRequest checks remain for UX; these methods hold lockForUpdate() and
 * re-check pending Leave so concurrent leave submission cannot strand requests.
 */
final class MutateDepartmentAttendanceLeaveParticipation
{
    /**
     * Apply a department update. When excluding from Attendance & Leave
     * (true → false), locks the department and re-checks pending leave.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Department $department, int $companyId, array $attributes): Department
    {
        if ((int) $department->company_id !== $companyId) {
            abort(404);
        }

        $wantExclude = array_key_exists('include_in_attendance_leave', $attributes)
            && (bool) $attributes['include_in_attendance_leave'] === false
            && (bool) $department->include_in_attendance_leave === true;

        $participationChanged = array_key_exists('include_in_attendance_leave', $attributes)
            && (bool) $attributes['include_in_attendance_leave'] !== (bool) $department->include_in_attendance_leave;

        if (! $wantExclude) {
            $department->update($attributes);

            if ($participationChanged) {
                DepartmentAttendanceLeaveGuard::forgetDashboardCache($companyId);
            }

            return $department->fresh() ?? $department;
        }

        $updated = DB::transaction(function () use ($department, $companyId, $attributes): Department {
            $locked = Department::query()
                ->where('company_id', $companyId)
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            $message = DepartmentAttendanceLeaveGuard::cannotExcludeDepartment($companyId, $locked);

            if ($message !== null) {
                throw ValidationException::withMessages([
                    'include_in_attendance_leave' => $message,
                ]);
            }

            $locked->update($attributes);

            return $locked->fresh() ?? $locked;
        });

        DepartmentAttendanceLeaveGuard::forgetDashboardCache($companyId);

        return $updated;
    }

    /**
     * Soft-delete a department after locking and re-checking pending leave.
     */
    public function delete(Department $department, int $companyId): void
    {
        if ((int) $department->company_id !== $companyId) {
            abort(404);
        }

        DB::transaction(function () use ($department, $companyId): void {
            $locked = Department::query()
                ->where('company_id', $companyId)
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            $message = DepartmentAttendanceLeaveGuard::cannotDeleteDepartment($companyId, $locked);

            if ($message !== null) {
                throw ValidationException::withMessages([
                    'department' => $message,
                ]);
            }

            $locked->delete();
        });

        DepartmentAttendanceLeaveGuard::forgetDashboardCache($companyId);
    }
}
