<?php

namespace App\Support\Attendance;

use App\Enums\LeaveTypeCategory;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;

final class AnnualLeaveEntitlementCalculator
{
    public const MESSAGE_HIRE_DATE_REQUIRED = 'Hire date required for annual leave allocation.';

    public const MESSAGE_BEFORE_EMPLOYMENT = 'Annual leave is not allocated for years before employment.';

    public const SKIP_MISSING_HIRE_DATE = 'missing_hire_date';

    public const SKIP_BEFORE_EMPLOYMENT = 'before_employment';

    public const SKIP_NOT_YET_JOINED = 'not_yet_joined';

    public const MESSAGE_NOT_YET_JOINED = 'Annual leave is allocated after the employee joining date.';

    /**
     * Entitlement for a newly created leave balance row.
     *
     * @return float|null Null when no balance should be provisioned (missing hire date, year before hire, etc.).
     */
    public function entitledDaysForNewBalance(LeaveType $leaveType, Employee $employee, int $year): ?float
    {
        if ($this->newBalanceAllocationSkipReason($leaveType, $employee, $year) !== null) {
            return null;
        }

        if ($leaveType->category !== LeaveTypeCategory::Annual) {
            return (float) $leaveType->days_per_year;
        }

        $hireDate = $employee->hire_date;
        $hireYear = (int) $hireDate->format('Y');

        if ($year > $hireYear) {
            return (float) $leaveType->days_per_year;
        }

        return $this->proRataForJoiningYear($hireDate, $year, (float) $leaveType->days_per_year);
    }

    /**
     * @return self::SKIP_MISSING_HIRE_DATE|self::SKIP_BEFORE_EMPLOYMENT|null
     */
    public function newBalanceAllocationSkipReason(LeaveType $leaveType, Employee $employee, int $year): ?string
    {
        if ($leaveType->category !== LeaveTypeCategory::Annual) {
            return null;
        }

        $hireDate = $employee->hire_date;

        if ($hireDate === null) {
            return self::SKIP_MISSING_HIRE_DATE;
        }

        $hireYear = (int) $hireDate->format('Y');

        if ($year < $hireYear) {
            return self::SKIP_BEFORE_EMPLOYMENT;
        }

        $companyId = (int) $employee->company_id;
        $today = now(CompanyTimezone::forCompanyId($companyId))->startOfDay();

        if ($hireDate->copy()->startOfDay()->gt($today)) {
            return self::SKIP_NOT_YET_JOINED;
        }

        return null;
    }

    public function skipMessageForReason(?string $reason): ?string
    {
        return match ($reason) {
            self::SKIP_MISSING_HIRE_DATE => self::MESSAGE_HIRE_DATE_REQUIRED,
            self::SKIP_BEFORE_EMPLOYMENT => self::MESSAGE_BEFORE_EMPLOYMENT,
            self::SKIP_NOT_YET_JOINED => self::MESSAGE_NOT_YET_JOINED,
            default => null,
        };
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
