<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\UniqueConstraintViolationException;
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
        return $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: false);
    }

    public function reserveLeaveRequest(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status !== 'pending') {
            return;
        }

        $this->reserveIfAvailable(
            companyId: (int) $leaveRequest->company_id,
            employeeId: (int) $leaveRequest->employee_id,
            leaveTypeId: (int) $leaveRequest->leave_type_id,
            startDate: (string) $leaveRequest->start_date?->toDateString(),
            endDate: (string) $leaveRequest->end_date?->toDateString(),
        );
    }

    public function releaseLeaveRequest(LeaveRequest $leaveRequest): void
    {
        if ($leaveRequest->status !== 'pending') {
            return;
        }

        $this->releasePendingReservation($leaveRequest);
    }

    public function approveLeaveRequest(LeaveRequest $leaveRequest): void
    {
        $this->convertPendingToUsed($leaveRequest);
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

        $this->replacePendingReservation($leaveRequest, $replacement);
    }

    /**
     * Lock year rows, validate remaining entitlement, then increment pending under the same locks.
     *
     * @throws RuntimeException
     */
    public function reserveIfAvailable(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        string $startDate,
        string $endDate,
        ?LeaveRequest $creditSameKeyRequest = null,
    ): void {
        if ($startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are required to reserve balance.');
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->where('status', 'active')
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The selected leave type is invalid.');
        }

        $run = function () use ($companyId, $employeeId, $leaveType, $startDate, $endDate, $creditSameKeyRequest): void {
            foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: true);
                $available = (float) $balance->remaining_days;

                if ($this->canCreditPendingRequest($creditSameKeyRequest, $companyId, $employeeId, (int) $leaveType->id, $year)) {
                    $available += $this->daysForRequestInYear($creditSameKeyRequest, $year);
                }

                if ($available + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Insufficient %s balance for %d. Only %.1f day(s) remaining.',
                        $leaveType->name,
                        $year,
                        max(0, $available),
                    ));
                }

                $balance->forceFill([
                    'pending_days' => (float) $balance->pending_days + $days,
                ])->save();
            }
        };

        $this->runInTransaction($run);
    }

    /**
     * Release exactly the request allocation from pending.
     * Missing dates, leave type, or insufficient pending is workflow corruption.
     *
     * @throws RuntimeException
     */
    public function releasePendingReservation(LeaveRequest $leaveRequest): void
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null || $startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are missing; cannot release balance reservation.');
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The leave type for this request is missing or does not belong to the request company.');
        }

        $run = function () use ($companyId, $employeeId, $leaveType, $startDate, $endDate): void {
            $updates = [];

            foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: true);
                $current = (float) $balance->pending_days;

                if ($current + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Cannot release leave: pending reservation for %s (%d) is missing or insufficient (have %.2f, need %.2f).',
                        $leaveType->name,
                        $year,
                        $current,
                        $days,
                    ));
                }

                $updates[] = [$balance, $current - $days];
            }

            foreach ($updates as [$balance, $pendingDays]) {
                $balance->forceFill([
                    'pending_days' => $pendingDays,
                ])->save();
            }
        };

        $this->runInTransaction($run);
    }

    /**
     * Convert pending reservation to used for every affected year.
     * Fails if pending does not contain the allocation — never increments used alone.
     *
     * @throws RuntimeException
     */
    public function convertPendingToUsed(LeaveRequest $leaveRequest): void
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null || $startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are missing; cannot convert balance reservation.');
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The leave type for this request is missing or does not belong to the request company.');
        }

        $run = function () use ($companyId, $employeeId, $leaveType, $startDate, $endDate): void {
            $updates = [];

            foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: true);
                $pending = (float) $balance->pending_days;

                if ($pending + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Cannot approve leave: pending reservation for %s (%d) is missing or insufficient (have %.2f, need %.2f).',
                        $leaveType->name,
                        $year,
                        $pending,
                        $days,
                    ));
                }

                $updates[] = [$balance, $pending - $days, (float) $balance->used_days + $days];
            }

            foreach ($updates as [$balance, $pendingDays, $usedDays]) {
                $balance->forceFill([
                    'pending_days' => $pendingDays,
                    'used_days' => $usedDays,
                ])->save();
            }
        };

        $this->runInTransaction($run);
    }

    /**
     * Release the old pending reservation and reserve the replacement under the same outer transaction.
     * All affected balance keys are locked in a deterministic order before any mutation.
     *
     * @param  array{
     *     employee_id: int,
     *     leave_type_id: int,
     *     start_date: string,
     *     end_date: string,
     * }  $replacement
     */
    public function replacePendingReservation(LeaveRequest $leaveRequest, array $replacement): void
    {
        $run = function () use ($leaveRequest, $replacement): void {
            $companyId = (int) $leaveRequest->company_id;
            $oldEmployeeId = (int) $leaveRequest->employee_id;
            $oldLeaveTypeId = (int) $leaveRequest->leave_type_id;
            $oldStartDate = $leaveRequest->start_date?->toDateString();
            $oldEndDate = $leaveRequest->end_date?->toDateString();

            if ($oldStartDate === null || $oldEndDate === null || $oldStartDate === '' || $oldEndDate === '') {
                throw new RuntimeException('Leave request dates are missing; cannot replace balance reservation.');
            }

            $newEmployeeId = (int) $replacement['employee_id'];
            $newLeaveTypeId = (int) $replacement['leave_type_id'];
            $newStartDate = (string) $replacement['start_date'];
            $newEndDate = (string) $replacement['end_date'];

            if ($newStartDate === '' || $newEndDate === '') {
                throw new RuntimeException('Leave request dates are required to reserve balance.');
            }

            $oldLeaveType = LeaveType::query()
                ->where('company_id', $companyId)
                ->whereKey($oldLeaveTypeId)
                ->first();

            if ($oldLeaveType === null) {
                throw new RuntimeException('The leave type for this request is missing or does not belong to the request company.');
            }

            $newLeaveType = LeaveType::query()
                ->where('company_id', $companyId)
                ->whereKey($newLeaveTypeId)
                ->where('status', 'active')
                ->first();

            if ($newLeaveType === null) {
                throw new RuntimeException('The selected leave type is invalid.');
            }

            $oldByYear = $this->daysByYear($oldStartDate, $oldEndDate);
            $newByYear = $this->daysByYear($newStartDate, $newEndDate);

            /** @var array<string, array{company_id: int, employee_id: int, leave_type_id: int, year: int, leave_type: LeaveType}> $lockTargets */
            $lockTargets = [];

            foreach ($oldByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $oldEmployeeId, $oldLeaveTypeId, (int) $year);
                $lockTargets[$key] = [
                    'company_id' => $companyId,
                    'employee_id' => $oldEmployeeId,
                    'leave_type_id' => $oldLeaveTypeId,
                    'year' => (int) $year,
                    'leave_type' => $oldLeaveType,
                ];
            }

            foreach ($newByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $newEmployeeId, $newLeaveTypeId, (int) $year);
                $lockTargets[$key] = [
                    'company_id' => $companyId,
                    'employee_id' => $newEmployeeId,
                    'leave_type_id' => $newLeaveTypeId,
                    'year' => (int) $year,
                    'leave_type' => $newLeaveType,
                ];
            }

            uksort($lockTargets, function (string $left, string $right) use ($lockTargets): int {
                $a = $lockTargets[$left];
                $b = $lockTargets[$right];

                return [$a['company_id'], $a['employee_id'], $a['leave_type_id'], $a['year']]
                    <=> [$b['company_id'], $b['employee_id'], $b['leave_type_id'], $b['year']];
            });

            /** @var array<string, LeaveBalance> $lockedBalances */
            $lockedBalances = [];

            foreach ($lockTargets as $key => $target) {
                $lockedBalances[$key] = $this->lockedBalance(
                    $target['company_id'],
                    $target['employee_id'],
                    $target['leave_type'],
                    $target['year'],
                    lock: true,
                );
            }

            foreach ($oldByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $oldEmployeeId, $oldLeaveTypeId, (int) $year);
                $balance = $lockedBalances[$key];
                $current = (float) $balance->pending_days;

                if ($current + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Cannot release leave: pending reservation for %s (%d) is missing or insufficient (have %.2f, need %.2f).',
                        $oldLeaveType->name,
                        $year,
                        $current,
                        $days,
                    ));
                }
            }

            foreach ($newByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $newEmployeeId, $newLeaveTypeId, (int) $year);
                $balance = $lockedBalances[$key];
                $available = (float) $balance->remaining_days;
                $oldDaysOnSameKey = 0.0;

                if (
                    $oldEmployeeId === $newEmployeeId
                    && $oldLeaveTypeId === $newLeaveTypeId
                    && isset($oldByYear[$year])
                    && $oldByYear[$year] > 0
                ) {
                    $oldDaysOnSameKey = (float) $oldByYear[$year];
                    $available += $oldDaysOnSameKey;
                }

                if ($available + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Insufficient %s balance for %d. Only %.1f day(s) remaining.',
                        $newLeaveType->name,
                        $year,
                        max(0, $available),
                    ));
                }
            }

            foreach ($oldByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $oldEmployeeId, $oldLeaveTypeId, (int) $year);
                $balance = $lockedBalances[$key];
                $balance->forceFill([
                    'pending_days' => (float) $balance->pending_days - $days,
                ])->save();
            }

            foreach ($newByYear as $year => $days) {
                if ($days <= 0) {
                    continue;
                }

                $key = $this->balanceLockKey($companyId, $newEmployeeId, $newLeaveTypeId, (int) $year);
                $balance = $lockedBalances[$key];
                $balance->forceFill([
                    'pending_days' => (float) $balance->pending_days + $days,
                ])->save();
            }
        };

        $this->runInTransaction($run);
    }

    private function balanceLockKey(int $companyId, int $employeeId, int $leaveTypeId, int $year): string
    {
        return implode(':', [$companyId, $employeeId, $leaveTypeId, $year]);
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

            $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: DB::transactionLevel() > 0);
            $available = (float) $balance->remaining_days;

            if ($this->canCreditPendingRequest($ignore, $companyId, $employeeId, $leaveTypeId, $year)) {
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
            $this->synchronizeBalanceKey($companyId, $employeeId, (int) $leaveType->id, $year);
            $synced++;
        }

        return $synced;
    }

    /**
     * Recalculate used/pending for one balance key under a short per-key transaction lock.
     */
    public function synchronizeBalanceKey(int $companyId, int $employeeId, int $leaveTypeId, int $year): void
    {
        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            return;
        }

        DB::transaction(function () use ($companyId, $employeeId, $leaveType, $leaveTypeId, $year): void {
            $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: true);

            $balance->forceFill([
                'used_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'approved'),
                'pending_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'pending'),
            ])->save();
        });
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

        $this->synchronizeBalanceKey($companyId, $employeeId, (int) $leaveType->id, $year);

        return true;
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

    private function canCreditPendingRequest(
        ?LeaveRequest $request,
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
    ): bool {
        if ($request === null || $request->status !== 'pending') {
            return false;
        }

        if ((int) $request->company_id !== $companyId) {
            return false;
        }

        if ((int) $request->employee_id !== $employeeId) {
            return false;
        }

        if ((int) $request->leave_type_id !== $leaveTypeId) {
            return false;
        }

        return $this->daysForRequestInYear($request, $year) > 0;
    }

    private function lockedBalance(
        int $companyId,
        int $employeeId,
        LeaveType $leaveType,
        int $year,
        bool $lock,
    ): LeaveBalance {
        $query = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year);

        if ($lock) {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if ($balance !== null) {
            return $balance;
        }

        try {
            $created = LeaveBalance::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveType->id,
                'year' => $year,
                'entitled_days' => $leaveType->days_per_year,
                'carried_days' => 0,
                'used_days' => 0,
                'pending_days' => 0,
            ]);

            if (! $lock) {
                return $created;
            }

            return LeaveBalance::query()
                ->whereKey($created->id)
                ->lockForUpdate()
                ->firstOrFail();
        } catch (UniqueConstraintViolationException) {
            $retry = LeaveBalance::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $year);

            if ($lock) {
                $retry->lockForUpdate();
            }

            return $retry->firstOrFail();
        }
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

        // Ascending year order is already guaranteed by the loop.
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

    private function runInTransaction(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            $callback();

            return;
        }

        DB::transaction($callback);
    }
}
