<?php

namespace App\Support\Attendance;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Settings\CompanyTimezone;

final class LeaveTypeYearBalance
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
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
     * }>
     */
    private function forCurrentOrFutureYear(int $companyId, int $employeeId, int $year): array
    {
        $this->leaveBalances->ensureEmployeeYear($companyId, $employeeId, $year);

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
            ->map(function (LeaveType $leaveType) use ($balances) {
                $balance = $balances->get($leaveType->id);

                if ($balance === null) {
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
        ];
    }
}
