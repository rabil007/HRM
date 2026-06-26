<?php

namespace App\Support\Payroll;

use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Collection;

final class OfficeLeavePeriodSummary
{
    public function __construct(
        private readonly CountLeaveDaysInRange $countDays,
    ) {}

    /**
     * @param  list<int>  $employeeIds
     * @return Collection<int, EmployeeLeavePeriodSummary>
     */
    public function forEmployees(
        int $companyId,
        string $periodStart,
        string $periodEnd,
        array $employeeIds,
    ): Collection {
        if ($employeeIds === []) {
            return Collection::make();
        }

        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color']);

        $requests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $periodEnd)
            ->whereDate('end_date', '>=', $periodStart)
            ->with('leaveType:id,name,code,color')
            ->get();

        /** @var array<int, array<int, float>> $daysByEmployeeAndType */
        $daysByEmployeeAndType = [];

        foreach ($requests as $request) {
            $startDate = $request->start_date?->toDateString();
            $endDate = $request->end_date?->toDateString();

            if ($startDate === null || $endDate === null) {
                continue;
            }

            $days = $this->countDays->count($startDate, $endDate, $periodStart, $periodEnd);

            if ($days <= 0) {
                continue;
            }

            $employeeId = (int) $request->employee_id;
            $leaveTypeId = (int) $request->leave_type_id;
            $daysByEmployeeAndType[$employeeId][$leaveTypeId] = ($daysByEmployeeAndType[$employeeId][$leaveTypeId] ?? 0.0) + $days;
        }

        return Collection::make($employeeIds)
            ->mapWithKeys(function (int $employeeId) use ($leaveTypes, $daysByEmployeeAndType) {
                $usageByType = $daysByEmployeeAndType[$employeeId] ?? [];
                $leaveUsage = [];
                $totalLeaveDays = 0.0;

                foreach ($leaveTypes as $leaveType) {
                    $days = round((float) ($usageByType[$leaveType->id] ?? 0.0), 2);
                    $totalLeaveDays += $days;

                    $leaveUsage[] = [
                        'leave_type_id' => $leaveType->id,
                        'code' => $leaveType->code,
                        'name' => $leaveType->name,
                        'color' => $leaveType->color,
                        'days' => $days,
                    ];
                }

                return [
                    $employeeId => new EmployeeLeavePeriodSummary(
                        totalLeaveDays: round($totalLeaveDays, 2),
                        leaveUsage: $leaveUsage,
                    ),
                ];
            });
    }

    public function empty(int $companyId): EmployeeLeavePeriodSummary
    {
        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color']);

        $leaveUsage = $leaveTypes->map(fn (LeaveType $leaveType) => [
            'leave_type_id' => $leaveType->id,
            'code' => $leaveType->code,
            'name' => $leaveType->name,
            'color' => $leaveType->color,
            'days' => 0.0,
        ])->values()->all();

        return new EmployeeLeavePeriodSummary(0.0, $leaveUsage);
    }
}
