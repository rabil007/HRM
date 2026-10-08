<?php

namespace App\Support\Attendance;

use App\Enums\LeaveTypeCategory;
use App\Models\Employee;
use App\Models\LeaveBalance;
use Carbon\CarbonInterface;

final class EmployeeHireDateChangeGuard
{
    public const ACKNOWLEDGMENT_INPUT = 'hire_date_change_acknowledged';

    /**
     * Request-scoped employee IDs whose next `updated` activity should omit
     * `hire_date` (covered by the dedicated `hire_date_changed` event).
     */
    public const OMIT_HIRE_DATE_FROM_UPDATE_ACTIVITY_EMPLOYEE_IDS = 'employee_hire_date_change.omit_hire_date_from_update_activity_employee_ids';

    public function markOmitHireDateFromNextUpdateActivityLog(int $employeeId): void
    {
        if ($employeeId <= 0) {
            return;
        }

        $key = self::OMIT_HIRE_DATE_FROM_UPDATE_ACTIVITY_EMPLOYEE_IDS;
        $ids = request()->attributes->get($key, []);

        if (! is_array($ids)) {
            $ids = [];
        }

        $ids[] = $employeeId;

        request()->attributes->set(
            $key,
            array_values(array_unique(array_map(intval(...), $ids))),
        );
    }

    public function shouldOmitHireDateFromUpdateActivityLog(Employee $employee): bool
    {
        $ids = request()->attributes->get(self::OMIT_HIRE_DATE_FROM_UPDATE_ACTIVITY_EMPLOYEE_IDS, []);

        if (! is_array($ids)) {
            return false;
        }

        return in_array((int) $employee->getKey(), array_map(intval(...), $ids), true);
    }

    public function normalizeHireDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    public function calendarDateChanged(?CarbonInterface $current, ?string $incoming): bool
    {
        $currentDate = $current?->toDateString();
        $incomingDate = $this->normalizeHireDate($incoming);

        return $currentDate !== $incomingDate;
    }

    public function requiresAcknowledgment(Employee $employee, int $companyId, ?string $proposedHireDate): bool
    {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if (! $this->calendarDateChanged($employee->hire_date, $proposedHireDate)) {
            return false;
        }

        return $this->hasAnnualLeaveBalances($companyId, (int) $employee->id);
    }

    public function hasAnnualLeaveBalances(int $companyId, int $employeeId): bool
    {
        return $this->annualLeaveBalanceYears($companyId, $employeeId) !== [];
    }

    /**
     * @return list<int>
     */
    public function annualLeaveBalanceYears(int $companyId, int $employeeId): array
    {
        if ($companyId <= 0 || $employeeId <= 0) {
            return [];
        }

        return LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->whereHas('leaveType', function ($query) use ($companyId): void {
                $query
                    ->withTrashed()
                    ->where('company_id', $companyId)
                    ->where('category', LeaveTypeCategory::Annual);
            })
            ->distinct()
            ->orderBy('year')
            ->pluck('year')
            ->map(fn ($year): int => (int) $year)
            ->values()
            ->all();
    }
}
