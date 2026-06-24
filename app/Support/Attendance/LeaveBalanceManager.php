<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LeaveBalanceManager
{
    public function __construct(
        private CalculateLeaveRequestDays $calculateDays,
    ) {}

    public function provisionEmployee(Employee $employee): void
    {
        $this->ensureEmployeeYear((int) $employee->company_id, (int) $employee->id, (int) now()->year);
    }

    public function provisionLeaveType(LeaveType $leaveType): void
    {
        if ($leaveType->status !== 'active') {
            return;
        }

        $companyId = (int) $leaveType->company_id;
        $year = (int) now()->year;

        Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select('id')
            ->chunkById(100, function (Collection $employees) use ($companyId, $leaveType, $year): void {
                foreach ($employees as $employee) {
                    $this->findOrCreateBalance($companyId, (int) $employee->id, $leaveType, $year);
                }
            });
    }

    public function ensureEmployeeYear(int $companyId, int $employeeId, int $year): void
    {
        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get();

        foreach ($leaveTypes as $leaveType) {
            $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);
        }
    }

    public function findOrCreateBalance(int $companyId, int $employeeId, LeaveType $leaveType, int $year): LeaveBalance
    {
        $balance = LeaveBalance::query()->firstOrCreate(
            [
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
            ],
            [
                'entitled_days' => $leaveType->days_per_year,
                'carried_days' => 0,
                'used_days' => 0,
                'pending_days' => 0,
            ],
        );

        if ($balance->wasRecentlyCreated) {
            $balance->refresh();
        }

        return $balance;
    }

    public function reserveLeaveRequest(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status !== 'pending') {
            return;
        }

        $this->adjustBucketsForRequest($leaveRequest, 'pending_days', 1.0);
    }

    public function releaseLeaveRequest(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status !== 'pending') {
            return;
        }

        $this->adjustBucketsForRequest($leaveRequest, 'pending_days', -1.0);
    }

    public function approveLeaveRequest(LeaveRequest $leaveRequest): void
    {
        $this->adjustBucketsForRequest($leaveRequest, 'pending_days', -1.0);
        $this->adjustBucketsForRequest($leaveRequest, 'used_days', 1.0);
    }

    /**
     * @param  array{
     *     employee_id: int,
     *     leave_type_id: int,
     *     start_date: string,
     *     end_date: string,
     * }  $replacement
     */
    public function replacePendingLeaveRequest(LeaveRequest $leaveRequest, array $replacement): void
    {
        if ($leaveRequest->status !== 'pending') {
            return;
        }

        $this->releaseLeaveRequest($leaveRequest);

        $temporary = $leaveRequest->replicate();
        $temporary->employee_id = $replacement['employee_id'];
        $temporary->leave_type_id = $replacement['leave_type_id'];
        $temporary->start_date = $replacement['start_date'];
        $temporary->end_date = $replacement['end_date'];
        $temporary->total_days = ($this->calculateDays)($replacement['start_date'], $replacement['end_date']);
        $temporary->status = 'pending';

        $this->reserveLeaveRequest($temporary);
    }

    /**
     * @throws RuntimeException
     */
    public function assertCanReserve(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        string $startDate,
        string $endDate,
        ?LeaveRequest $ignore = null,
    ): void {
        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->where('status', 'active')
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The selected leave type is invalid.');
        }

        foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
            if ($days <= 0) {
                continue;
            }

            $balance = $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);
            $available = (float) $balance->remaining_days;

            if ($ignore !== null && $ignore->status === 'pending') {
                $available += $this->daysForRequestInYear($ignore, $year);
            }

            if ($available + 0.0001 < $days) {
                throw new RuntimeException(sprintf(
                    'Insufficient %s balance for %d. Only %.1f day(s) remaining.',
                    $leaveType->name,
                    $year,
                    max(0, $available),
                ));
            }
        }
    }

    public function rolloverCompany(int $companyId, int $year): int
    {
        $previousYear = $year - 1;
        $created = 0;

        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get();

        if ($leaveTypes->isEmpty()) {
            return 0;
        }

        Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select('id')
            ->chunkById(100, function (Collection $employees) use ($companyId, $leaveTypes, $year, $previousYear, &$created): void {
                foreach ($employees as $employee) {
                    foreach ($leaveTypes as $leaveType) {
                        if ($this->rolloverEmployeeLeaveType($companyId, (int) $employee->id, $leaveType, $year, $previousYear)) {
                            $created++;
                        }
                    }
                }
            });

        return $created;
    }

    public function syncCompany(int $companyId, ?int $year = null): int
    {
        $years = $year !== null
            ? [$year]
            : $this->yearsWithLeaveActivity($companyId);

        if ($years === []) {
            $years = [(int) now()->year];
        }

        $synced = 0;

        Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->select('id')
            ->chunkById(100, function (Collection $employees) use ($companyId, $years, &$synced): void {
                foreach ($employees as $employee) {
                    foreach ($years as $targetYear) {
                        $this->ensureEmployeeYear($companyId, (int) $employee->id, $targetYear);
                        $synced += $this->syncEmployeeYear($companyId, (int) $employee->id, $targetYear);
                    }
                }
            });

        return $synced;
    }

    public function syncEmployeeYear(int $companyId, int $employeeId, int $year): int
    {
        $leaveTypes = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->get();

        $synced = 0;

        foreach ($leaveTypes as $leaveType) {
            $balance = $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);
            $usedDays = $this->sumRequestDaysForYear($companyId, $employeeId, $leaveType->id, $year, 'approved');
            $pendingDays = $this->sumRequestDaysForYear($companyId, $employeeId, $leaveType->id, $year, 'pending');

            $balance->forceFill([
                'used_days' => $usedDays,
                'pending_days' => $pendingDays,
            ])->save();

            $synced++;
        }

        return $synced;
    }

    /**
     * @return list<int>
     */
    private function yearsWithLeaveActivity(int $companyId): array
    {
        return LeaveRequest::query()
            ->where('company_id', $companyId)
            ->selectRaw('DISTINCT YEAR(start_date) as year')
            ->pluck('year')
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->push((int) now()->year)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function rolloverEmployeeLeaveType(
        int $companyId,
        int $employeeId,
        LeaveType $leaveType,
        int $year,
        int $previousYear,
    ): bool {
        $existing = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->exists();

        if ($existing) {
            return false;
        }

        $previousBalance = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $previousYear)
            ->first();

        $carriedDays = 0.0;

        if ($leaveType->carry_forward && $previousBalance !== null) {
            $remaining = max(0, (float) $previousBalance->remaining_days);
            $carriedDays = min($remaining, (float) $leaveType->max_carry_days);
        }

        LeaveBalance::query()->create([
            'company_id' => $companyId,
            'employee_id' => $employeeId,
            'leave_type_id' => $leaveType->id,
            'year' => $year,
            'entitled_days' => $leaveType->days_per_year,
            'carried_days' => $carriedDays,
            'used_days' => 0,
            'pending_days' => 0,
        ]);

        $this->syncEmployeeLeaveTypeYear($companyId, $employeeId, $leaveType->id, $year);

        return true;
    }

    private function syncEmployeeLeaveTypeYear(int $companyId, int $employeeId, int $leaveTypeId, int $year): void
    {
        $leaveType = LeaveType::query()->find($leaveTypeId);

        if ($leaveType === null) {
            return;
        }

        $balance = $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);

        $balance->forceFill([
            'used_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'approved'),
            'pending_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'pending'),
        ])->save();
    }

    private function sumRequestDaysForYear(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        string $status,
    ): float {
        $total = 0.0;

        $leaveRequests = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', $status)
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->get(['start_date', 'end_date']);

        foreach ($leaveRequests as $leaveRequest) {
            $total += $this->daysForRequestInYear($leaveRequest, $year);
        }

        return $total;
    }

    private function adjustBucketsForRequest(LeaveRequest $leaveRequest, string $bucket, float $direction): void
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null) {
            return;
        }

        $leaveType = LeaveType::query()->find($leaveTypeId);

        if ($leaveType === null) {
            return;
        }

        DB::transaction(function () use ($companyId, $employeeId, $leaveType, $startDate, $endDate, $bucket, $direction): void {
            foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $balance = $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);
                $delta = $days * $direction;
                $nextValue = max(0, (float) $balance->{$bucket} + $delta);

                $balance->forceFill([
                    $bucket => $nextValue,
                ])->save();
            }
        });
    }

    /**
     * @return array<int, float>
     */
    private function daysByYear(string $startDate, string $endDate): array
    {
        $startYear = (int) date('Y', strtotime($startDate));
        $endYear = (int) date('Y', strtotime($endDate));
        $allocations = [];

        for ($year = $startYear; $year <= $endYear; $year++) {
            $allocations[$year] = $this->daysWithinYear($startDate, $endDate, $year);
        }

        return $allocations;
    }

    private function daysWithinYear(string $startDate, string $endDate, int $year): float
    {
        $yearStart = "{$year}-01-01";
        $yearEnd = "{$year}-12-31";
        $clippedStart = max($startDate, $yearStart);
        $clippedEnd = min($endDate, $yearEnd);

        if ($clippedStart > $clippedEnd) {
            return 0.0;
        }

        return ($this->calculateDays)($clippedStart, $clippedEnd);
    }

    private function daysForRequestInYear(LeaveRequest $leaveRequest, int $year): float
    {
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null) {
            return 0.0;
        }

        return $this->daysWithinYear($startDate, $endDate, $year);
    }
}
