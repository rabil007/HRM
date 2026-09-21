<?php

namespace App\Support\Payroll;

use App\Enums\LeaveTypePayrollTreatment;
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

        $requests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereIn('employee_id', $employeeIds)
            ->whereDate('start_date', '<=', $periodEnd)
            ->whereDate('end_date', '>=', $periodStart)
            ->get();

        $referencedTypeIds = $requests
            ->pluck('leave_type_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $leaveTypes = $this->leaveTypesForPeriod($companyId, $referencedTypeIds);

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

                    $leaveUsage[] = $this->usageRow($leaveType, $days);
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
        $leaveTypes = $this->leaveTypesForPeriod($companyId, []);

        $leaveUsage = $leaveTypes
            ->map(fn (LeaveType $leaveType) => $this->usageRow($leaveType, 0.0))
            ->values()
            ->all();

        return new EmployeeLeavePeriodSummary(0.0, $leaveUsage);
    }

    /**
     * Active leave types for zero-value presentation, plus any type referenced by
     * approved leave in the period (including inactive / soft-deleted types).
     *
     * @param  list<int>  $referencedTypeIds
     * @return Collection<int, LeaveType>
     */
    private function leaveTypesForPeriod(int $companyId, array $referencedTypeIds): Collection
    {
        $active = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get(['id', 'name', 'code', 'color', 'payroll_treatment', 'status']);

        if ($referencedTypeIds === []) {
            return $active->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        }

        $referenced = LeaveType::withTrashed()
            ->where('company_id', $companyId)
            ->whereIn('id', $referencedTypeIds)
            ->get(['id', 'name', 'code', 'color', 'payroll_treatment', 'status']);

        return $active
            ->concat($referenced)
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * @return array{
     *     leave_type_id: int,
     *     code: string,
     *     name: string,
     *     color: string|null,
     *     days: float,
     *     payroll_treatment: string,
     * }
     */
    private function usageRow(LeaveType $leaveType, float $days): array
    {
        $treatment = $leaveType->payroll_treatment instanceof LeaveTypePayrollTreatment
            ? $leaveType->payroll_treatment
            : LeaveTypePayrollTreatment::tryFrom((string) $leaveType->payroll_treatment)
                ?? LeaveTypePayrollTreatment::Paid;

        return [
            'leave_type_id' => $leaveType->id,
            'code' => $leaveType->code,
            'name' => $leaveType->name,
            'color' => $leaveType->color,
            'days' => $days,
            'payroll_treatment' => $treatment->value,
        ];
    }
}
