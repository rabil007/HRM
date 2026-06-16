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
     * @return list<array{
     *     id: int,
     *     name: string,
     *     code: string,
     *     color: string|null,
     *     entitled_days: float,
     *     carried_days: float,
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
                    return [
                        'id' => $leaveType->id,
                        'name' => $leaveType->name,
                        'code' => $leaveType->code,
                        'color' => $leaveType->color,
                        'entitled_days' => (float) $leaveType->days_per_year,
                        'carried_days' => 0.0,
                        'used_days' => 0.0,
                        'pending_days' => 0.0,
                        'remaining_days' => (float) $leaveType->days_per_year,
                    ];
                }

                return [
                    'id' => $leaveType->id,
                    'name' => $leaveType->name,
                    'code' => $leaveType->code,
                    'color' => $leaveType->color,
                    'entitled_days' => $balance->totalPoolDays(),
                    'carried_days' => (float) $balance->carried_days,
                    'used_days' => (float) $balance->used_days,
                    'pending_days' => (float) $balance->pending_days,
                    'remaining_days' => max(0, (float) $balance->remaining_days),
                ];
            })
            ->values()
            ->all();
    }
}
