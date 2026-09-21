<?php

namespace App\Support\Attendance;

use App\Models\LeaveBalance;
use App\Models\LeaveType;

final class LeaveTypeYearBalance
{
    public function __construct(
        private LeaveBalanceManager $leaveBalances,
    ) {}

    /**
     * Canonical balance presentation for one employee/year.
     *
     * Database: entitled_days = base entitlement, carried_days = carry.
     * Response:
     * - base_entitlement_days — base only
     * - carried_days — carry only
     * - total_available_days — base + carry
     * - entitled_days — compatibility alias for total_available_days (not base alone)
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
     *     used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     * }>
     */
    public function forEmployee(int $companyId, int $employeeId, int $year): array
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
     *     used_days: float,
     *     pending_days: float,
     *     remaining_days: float,
     * }
     */
    private function presentBalance(
        LeaveType $leaveType,
        float $baseEntitlementDays,
        float $carriedDays,
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
            'used_days' => $usedDays,
            'pending_days' => $pendingDays,
            'remaining_days' => $remainingDays,
        ];
    }
}
