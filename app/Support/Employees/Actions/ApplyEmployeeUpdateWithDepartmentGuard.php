<?php

namespace App\Support\Employees\Actions;

use App\Models\Department;
use App\Models\Employee;
use App\Support\Attendance\DepartmentAttendanceLeaveGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies employee attribute updates with a transaction-time department move guard.
 *
 * When department_id is unchanged (or omitted), performs a normal update.
 * When it changes, locks the employee row, locks a non-null destination
 * Department, re-checks pending-leave eligibility against that locked state,
 * then updates — so concurrent leave submission / department exclusion cannot
 * strand a pending request.
 */
final class ApplyEmployeeUpdateWithDepartmentGuard
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array{employee: Employee, department_changed: bool}
     */
    public function handle(Employee $employee, int $companyId, array $attributes): array
    {
        if ((int) $employee->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'department_id' => 'The selected employee is invalid for this company.',
            ]);
        }

        $previousDepartmentId = $employee->department_id !== null
            ? (int) $employee->department_id
            : null;

        $departmentChanging = array_key_exists('department_id', $attributes)
            && $previousDepartmentId !== (
                $attributes['department_id'] !== null
                    ? (int) $attributes['department_id']
                    : null
            );

        if (! $departmentChanging) {
            $employee->update($attributes);

            return [
                'employee' => $employee->fresh() ?? $employee,
                'department_changed' => false,
            ];
        }

        $result = DB::transaction(function () use ($employee, $companyId, $attributes): array {
            $locked = Employee::query()
                ->where('company_id', $companyId)
                ->whereKey($employee->id)
                ->lockForUpdate()
                ->firstOrFail();

            $nextDepartmentId = $attributes['department_id'] !== null
                ? (int) $attributes['department_id']
                : null;

            // Lock destination so concurrent exclude/delete cannot race past the
            // pending-leave move check. Lock order: employee → destination dept.
            $destination = null;

            if ($nextDepartmentId !== null) {
                $destination = Department::query()
                    ->where('company_id', $companyId)
                    ->whereKey($nextDepartmentId)
                    ->lockForUpdate()
                    ->first();

                if ($destination === null) {
                    throw ValidationException::withMessages([
                        'department_id' => 'The selected department is not available.',
                    ]);
                }
            }

            $message = DepartmentAttendanceLeaveGuard::cannotMoveEmployeeToDepartment(
                $locked,
                $companyId,
                $nextDepartmentId,
                $destination,
            );

            if ($message !== null) {
                throw ValidationException::withMessages([
                    'department_id' => $message,
                ]);
            }

            $locked->update($attributes);

            return [
                'employee' => $locked->fresh() ?? $locked,
                'department_changed' => true,
            ];
        });

        DepartmentAttendanceLeaveGuard::forgetDashboardCache($companyId);

        return $result;
    }
}
