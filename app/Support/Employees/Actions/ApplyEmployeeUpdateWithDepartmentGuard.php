<?php

namespace App\Support\Employees\Actions;

use App\Models\Employee;
use App\Support\Attendance\DepartmentAttendanceLeaveGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies employee attribute updates with a transaction-time department move guard.
 *
 * When department_id is unchanged (or omitted), performs a normal update.
 * When it changes, locks the employee row, re-checks pending-leave eligibility,
 * then updates — so concurrent leave submission cannot strand a pending request.
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

            $message = DepartmentAttendanceLeaveGuard::cannotMoveEmployeeToDepartment(
                $locked,
                $companyId,
                $nextDepartmentId,
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
