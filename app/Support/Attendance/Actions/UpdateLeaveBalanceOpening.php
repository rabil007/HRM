<?php

namespace App\Support\Attendance\Actions;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records previous (pre-OMS) leave usage on a current-year leave balance.
 *
 * Reusable by future bulk import; only mutates opening_* fields.
 */
final class UpdateLeaveBalanceOpening
{
    /**
     * @param  array{
     *     opening_used_days: float|int|string,
     *     opening_balance_as_of?: string|null,
     *     opening_balance_note?: string|null,
     * }  $attributes
     */
    public function handle(
        LeaveBalance $leaveBalance,
        User $actor,
        int $companyId,
        array $attributes,
    ): LeaveBalance {
        return DB::transaction(function () use ($leaveBalance, $actor, $companyId, $attributes): LeaveBalance {
            $locked = LeaveBalance::query()
                ->whereKey($leaveBalance->id)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertEditable($locked, $actor, $companyId);

            $openingUsed = round((float) $attributes['opening_used_days'], 2);

            if ($openingUsed <= 0) {
                $openingUsed = 0.0;
                $asOf = null;
                $note = null;
            } else {
                $asOf = $attributes['opening_balance_as_of'] ?? null;
                $note = filled($attributes['opening_balance_note'] ?? null)
                    ? trim((string) $attributes['opening_balance_note'])
                    : null;
            }

            $before = [
                'opening_used_days' => (float) $locked->opening_used_days,
                'opening_balance_as_of' => $locked->opening_balance_as_of?->toDateString(),
                'opening_balance_note' => $locked->opening_balance_note,
            ];

            $locked->disableLogging();
            $locked->forceFill([
                'opening_used_days' => $openingUsed,
                'opening_balance_as_of' => $asOf,
                'opening_balance_note' => $note,
            ])->save();
            $locked->enableLogging();

            $locked->refresh();

            activity()
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('updated')
                ->withProperties([
                    'company_id' => $companyId,
                    'employee_id' => (int) $locked->employee_id,
                    'leave_type_id' => (int) $locked->leave_type_id,
                    'year' => (int) $locked->year,
                    'old' => $before,
                    'attributes' => [
                        'opening_used_days' => (float) $locked->opening_used_days,
                        'opening_balance_as_of' => $locked->opening_balance_as_of?->toDateString(),
                        'opening_balance_note' => $locked->opening_balance_note,
                    ],
                ])
                ->log('Updated leave opening balance');

            return $locked;
        });
    }

    public function assertEditable(LeaveBalance $balance, User $actor, int $companyId): void
    {
        if ((int) $balance->company_id !== $companyId) {
            abort(404);
        }

        $businessYear = (int) now(CompanyTimezone::forCompanyId($companyId))->year;

        if ((int) $balance->year !== $businessYear) {
            throw ValidationException::withMessages([
                'leave_balance' => 'Opening balances can only be edited for the current business year.',
            ]);
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($balance->employee_id)
            ->first();

        if ($employee === null) {
            abort(404);
        }

        if (! in_array((string) $employee->status, ['active', 'on_leave'], true)) {
            throw ValidationException::withMessages([
                'leave_balance' => 'Opening balances can only be edited for active employees.',
            ]);
        }

        if (! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
            abort(403);
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $companyId)) {
            abort(403);
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($balance->leave_type_id)
            ->first();

        if ($leaveType === null || (string) $leaveType->status !== 'active') {
            throw ValidationException::withMessages([
                'leave_balance' => 'Opening balances can only be edited for active leave types.',
            ]);
        }
    }

    public static function isEditable(LeaveBalance $balance, ?User $actor, int $companyId, int $businessYear): bool
    {
        if ($actor === null) {
            return false;
        }

        if ((int) $balance->company_id !== $companyId) {
            return false;
        }

        if ((int) $balance->year !== $businessYear) {
            return false;
        }

        $employee = $balance->relationLoaded('employee')
            ? $balance->employee
            : Employee::query()->where('company_id', $companyId)->whereKey($balance->employee_id)->first();

        if ($employee === null || ! in_array((string) $employee->status, ['active', 'on_leave'], true)) {
            return false;
        }

        if (! EmployeeVisibilityScope::canAccess($actor, $employee, $companyId)) {
            return false;
        }

        if (! AttendanceLeaveDepartmentScope::canAccessEmployee($employee, $companyId)) {
            return false;
        }

        $leaveType = $balance->relationLoaded('leaveType')
            ? $balance->leaveType
            : LeaveType::query()->where('company_id', $companyId)->whereKey($balance->leave_type_id)->first();

        if ($leaveType === null || $leaveType->trashed() || (string) $leaveType->status !== 'active') {
            return false;
        }

        return true;
    }
}
