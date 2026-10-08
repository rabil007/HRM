<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Settings\CompanyTimezone;

final class LeaveTypeYearBalance
{
    public const SKIP_INACTIVE_EMPLOYEE = 'inactive_employee';

    public const MESSAGE_INACTIVE_EMPLOYEE = 'No leave balance allocated for this inactive employee.';

    public function __construct(
        private LeaveBalanceManager $leaveBalances,
        private AnnualLeaveEntitlementCalculator $annualEntitlement,
    ) {}

    /**
     * Canonical balance presentation for one employee/year.
     *
     * Database: entitled_days = base entitlement, carried_days = carry,
     * used_days = OMS-HRM approved requests, opening_used_days = pre-OMS usage.
     * Response:
     * - base_entitlement_days — base only
     * - carried_days — carry only
     * - total_available_days — base + carry
     * - entitled_days — compatibility alias for total_available_days (not base alone)
     * - used_days — OMS-HRM approved request usage only
     * - opening_used_days — previous/opening usage
     * - total_used_days — opening + HRM usage (employee-facing "Used")
     * - allocation_status — allocated | unallocated
     * - allocation_skip_reason — missing_hire_date | before_employment | not_yet_joined | inactive_employee | null
     * - allocation_message — HR-facing reason when unallocated
     *
     * Historical years (before the company business year) are read-only: never
     * provision balances and never invent entitlement from today's LeaveType rules.
     *
     * @return list<array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     base_entitlement_days: float,
     *     carried_days: float,
     *     total_available_days: float,
     *     entitled_days: float,
     *     opening_used_days: float,
     *     used_days: float,
     *     total_used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     *     allocation_status: string,
     *     allocation_skip_reason: string|null,
     *     allocation_message: string|null,
     * }>
     */
    public function forEmployee(int $companyId, int $employeeId, int $year): array
    {
        $businessYear = (int) now(CompanyTimezone::forCompanyId($companyId))->year;

        if ($year < $businessYear) {
            return $this->forHistoricalYear($companyId, $employeeId, $year);
        }

        return $this->forCurrentOrFutureYear($companyId, $employeeId, $year);
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     base_entitlement_days: float,
     *     carried_days: float,
     *     total_available_days: float,
     *     entitled_days: float,
     *     opening_used_days: float,
     *     used_days: float,
     *     total_used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     *     allocation_status: string,
     *     allocation_skip_reason: string|null,
     *     allocation_message: string|null,
     * }>
     */
    private function forCurrentOrFutureYear(int $companyId, int $employeeId, int $year): array
    {
        if (! AttendanceLeaveDepartmentScope::canAccessEmployeeId($employeeId, $companyId)) {
            return [];
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first(['id', 'company_id', 'department_id', 'status', 'hire_date']);

        if ($employee === null) {
            return [];
        }

        if ((string) $employee->status === 'active') {
            $this->leaveBalances->ensureEmployeeYear($companyId, $employeeId, $year);
        }

        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        $balances = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->whereIn('leave_type_id', $leaveTypes->pluck('id'))
            ->get()
            ->keyBy('leave_type_id');

        return $leaveTypes
            ->map(function (LeaveType $leaveType) use ($balances, $employee, $year) {
                $balance = $balances->get($leaveType->id);

                if ($balance === null) {
                    if ((string) $employee->status !== 'active') {
                        return $this->presentUnallocatedBalance(
                            $leaveType,
                            self::SKIP_INACTIVE_EMPLOYEE,
                            self::MESSAGE_INACTIVE_EMPLOYEE,
                        );
                    }

                    $skipReason = $this->annualEntitlement->newBalanceAllocationSkipReason(
                        $leaveType,
                        $employee,
                        $year,
                    );

                    if ($skipReason !== null) {
                        return $this->presentUnallocatedBalance($leaveType, $skipReason);
                    }

                    $base = (float) $leaveType->days_per_year;

                    return $this->presentBalance(
                        leaveType: $leaveType,
                        baseEntitlementDays: $base,
                        carriedDays: 0.0,
                        openingUsedDays: 0.0,
                        usedDays: 0.0,
                        pendingDays: 0.0,
                        remainingDays: $base,
                    );
                }

                $base = (float) $balance->entitled_days;
                $carried = (float) $balance->carried_days;

                return $this->presentBalance(
                    leaveType: $leaveType,
                    baseEntitlementDays: $base,
                    carriedDays: $carried,
                    openingUsedDays: (float) $balance->opening_used_days,
                    usedDays: (float) $balance->used_days,
                    pendingDays: (float) $balance->pending_days,
                    remainingDays: max(0, (float) $balance->remaining_days),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     base_entitlement_days: float,
     *     carried_days: float,
     *     total_available_days: float,
     *     entitled_days: float,
     *     opening_used_days: float,
     *     used_days: float,
     *     total_used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     *     allocation_status: string,
     *     allocation_skip_reason: string|null,
     *     allocation_message: string|null,
     * }>
     */
    private function forHistoricalYear(int $companyId, int $employeeId, int $year): array
    {
        $balances = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->with([
                'leaveType' => fn ($query) => $query->withTrashed(),
            ])
            ->get();

        return $balances
            ->filter(fn (LeaveBalance $balance): bool => $balance->leaveType instanceof LeaveType)
            ->sortBy(fn (LeaveBalance $balance): string => (string) $balance->leaveType->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->map(function (LeaveBalance $balance) {
                $leaveType = $balance->leaveType;
                $base = (float) $balance->entitled_days;
                $carried = (float) $balance->carried_days;

                return $this->presentBalance(
                    leaveType: $leaveType,
                    baseEntitlementDays: $base,
                    carriedDays: $carried,
                    openingUsedDays: (float) $balance->opening_used_days,
                    usedDays: (float) $balance->used_days,
                    pendingDays: (float) $balance->pending_days,
                    remainingDays: max(0, (float) $balance->remaining_days),
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  AnnualLeaveEntitlementCalculator::SKIP_MISSING_HIRE_DATE|AnnualLeaveEntitlementCalculator::SKIP_BEFORE_EMPLOYMENT|AnnualLeaveEntitlementCalculator::SKIP_NOT_YET_JOINED|self::SKIP_INACTIVE_EMPLOYEE  $skipReason
     * @return array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     base_entitlement_days: float,
     *     carried_days: float,
     *     total_available_days: float,
     *     entitled_days: float,
     *     opening_used_days: float,
     *     used_days: float,
     *     total_used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     *     allocation_status: string,
     *     allocation_skip_reason: string|null,
     *     allocation_message: string|null,
     * }
     */
    private function presentUnallocatedBalance(
        LeaveType $leaveType,
        string $skipReason,
        ?string $allocationMessage = null,
    ): array {
        return $this->presentBalance(
            leaveType: $leaveType,
            baseEntitlementDays: 0.0,
            carriedDays: 0.0,
            openingUsedDays: 0.0,
            usedDays: 0.0,
            pendingDays: 0.0,
            remainingDays: 0.0,
            allocationStatus: 'unallocated',
            allocationSkipReason: $skipReason,
            allocationMessage: $allocationMessage ?? $this->annualEntitlement->skipMessageForReason($skipReason),
        );
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     base_entitlement_days: float,
     *     carried_days: float,
     *     total_available_days: float,
     *     entitled_days: float,
     *     opening_used_days: float,
     *     used_days: float,
     *     total_used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     *     allocation_status: string,
     *     allocation_skip_reason: string|null,
     *     allocation_message: string|null,
     * }
     */
    private function presentBalance(
        LeaveType $leaveType,
        float $baseEntitlementDays,
        float $carriedDays,
        float $openingUsedDays,
        float $usedDays,
        float $pendingDays,
        float $remainingDays,
        string $allocationStatus = 'allocated',
        ?string $allocationSkipReason = null,
        ?string $allocationMessage = null,
    ): array {
        $totalAvailable = $baseEntitlementDays + $carriedDays;

        return [
            'id' => $leaveType->id,
            'name' => $leaveType->name,
            'code' => $leaveType->code,
            'color' => $leaveType->color,
            'base_entitlement_days' => $baseEntitlementDays,
            'carried_days' => $carriedDays,
            'total_available_days' => $totalAvailable,
            // Compatibility: historical consumers treated entitled_days as the full pool.
            'entitled_days' => $totalAvailable,
            'opening_used_days' => $openingUsedDays,
            'used_days' => $usedDays,
            'total_used_days' => $openingUsedDays + $usedDays,
            'pending_days' => $pendingDays,
            'remaining_days' => $remainingDays,
            'allocation_status' => $allocationStatus,
            'allocation_skip_reason' => $allocationSkipReason,
            'allocation_message' => $allocationMessage,
        ];
    }
}
