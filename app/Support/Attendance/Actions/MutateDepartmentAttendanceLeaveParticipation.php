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
     * Apply a department update. When include_in_attendance_leave is present,
     * locks the trusted row and decides exclusion protection from the LOCKED
     * current value (not a stale route-model snapshot).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Department $department, int $companyId, array $attributes): Department
    {
        if ((int) $department->company_id !== $companyId) {
            abort(404);
        }

        if (! array_key_exists('include_in_attendance_leave', $attributes)) {
            $department->update($attributes);

            return $department->fresh() ?? $department;
        }

        $requestedParticipation = (bool) $attributes['include_in_attendance_leave'];

        $result = DB::transaction(function () use ($department, $companyId, $attributes, $requestedParticipation): array {
            $locked = Department::query()
                ->where('company_id', $companyId)
                ->whereKey($department->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentParticipation = (bool) $locked->include_in_attendance_leave;
            $participationChanged = $currentParticipation !== $requestedParticipation;

            if ($currentParticipation && ! $requestedParticipation) {
                $message = DepartmentAttendanceLeaveGuard::cannotExcludeDepartment($companyId, $locked);

                if ($message !== null) {
                    throw ValidationException::withMessages([
                        'include_in_attendance_leave' => $message,
                    ]);
                }
            }

            $locked->update($attributes);

            return [
                'department' => $locked->fresh() ?? $locked,
                'participation_changed' => $participationChanged,
            ];
        });

        if ($result['participation_changed']) {
            DepartmentAttendanceLeaveGuard::forgetDashboardCache($companyId);
        }

        return $result['department'];
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
