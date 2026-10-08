<?php

namespace App\Support\Attendance;

use App\Enums\LeaveTypeCategory;
use App\Models\Employee;
use App\Models\LeaveType;
use Carbon\CarbonInterface;

final class AnnualLeaveEntitlementCalculator
{
    /**
     * Entitlement for a newly created leave balance row.
     *
     * @return float|null Null when no balance should be provisioned for this employee/year (e.g. year before hire).
     */
    public function entitledDaysForNewBalance(LeaveType $leaveType, Employee $employee, int $year): ?float
    {
        if ($leaveType->category !== LeaveTypeCategory::Annual) {
            return (float) $leaveType->days_per_year;
        }

        $hireDate = $employee->hire_date;

        if ($hireDate === null) {
            return 0.0;
        }

        $hireYear = (int) $hireDate->format('Y');

        if ($year < $hireYear) {
            return null;
        }

        if ($year > $hireYear) {
            return (float) $leaveType->days_per_year;
        }

        return $this->proRataForJoiningYear($hireDate, $year, (float) $leaveType->days_per_year);
    }

    public function proRataForJoiningYear(CarbonInterface $hireDate, int $year, float $configuredAnnualEntitlement): float
    {
        $hire = $hireDate->copy()->startOfDay();
        $yearEnd = $hire->copy()->setDate($year, 12, 31)->startOfDay();

        if ($hire->year !== $year || $hire->gt($yearEnd)) {
            return 0.0;
        }

        $eligibleDays = (int) $hire->diffInDays($yearEnd) + 1;
        $totalDays = $this->calendarDaysInYear($year);

        return $this->ceilEntitlement($eligibleDays, $totalDays, $configuredAnnualEntitlement);
    }

    public function calendarDaysInYear(int $year): int
    {
        return (int) (($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 366 : 365);
    }

    private function ceilEntitlement(int $eligibleDays, int $totalDays, float $configuredAnnualEntitlement): float
    {
        if ($eligibleDays <= 0 || $totalDays <= 0 || $configuredAnnualEntitlement <= 0) {
            return 0.0;
        }

        $numerator = bcmul((string) $eligibleDays, (string) $configuredAnnualEntitlement, 6);
        $quotient = bcdiv($numerator, (string) $totalDays, 6);

        return (float) (int) ceil((float) $quotient);
    }
}
