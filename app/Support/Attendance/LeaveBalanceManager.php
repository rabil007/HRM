<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Settings\CompanyTimezone;
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
        $companyId = (int) $employee->company_id;
        $this->ensureEmployeeYear($companyId, (int) $employee->id, $this->businessYearForCompany($companyId));
    }

    public function provisionLeaveType(LeaveType $leaveType): void
    {
        if ($leaveType->status !== 'active') {
            return;
        }

        $companyId = (int) $leaveType->company_id;
        $year = $this->businessYearForCompany($companyId);

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
     * Subtract the request allocation from used_days for every affected year.
     * Missing dates, leave type, or insufficient used is workflow corruption.
     *
     * @throws RuntimeException
     */
    public function releaseUsedAllocation(LeaveRequest $leaveRequest): void
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null || $startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are missing; cannot reverse used leave balance.');
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
                $current = (float) $balance->used_days;

                if ($current + 0.0001 < $days) {
                    throw new RuntimeException(sprintf(
                        'Cannot reverse leave: used allocation for %s (%d) is missing or insufficient (have %.2f, need %.2f).',
                        $leaveType->name,
                        $year,
                        $current,
                        $days,
                    ));
                }

                $updates[] = [$balance, $current - $days];
            }

            foreach ($updates as [$balance, $usedDays]) {
                $balance->forceFill([
                    'used_days' => $usedDays,
                ])->save();
            }
        };

        $this->runInTransaction($run);
    }

    /**
     * Lock and snapshot allocation balances for the request date span in deterministic year order.
     * May create missing LeaveBalance rows (provisioning path). Prefer
     * inspectExistingAllocationBalances() for read-only workflows.
     *
     * @return list<array{
     *     year: int,
     *     days: float,
     *     pending_days: float,
     *     used_days: float,
     *     remaining_days: float
     * }>
     */
    public function snapshotAllocationBalances(LeaveRequest $leaveRequest, bool $lock = true): array
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null || $startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are missing; cannot snapshot leave balances.');
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The leave type for this request is missing or does not belong to the request company.');
        }

        $snapshots = [];

        foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
            if ($days <= 0) {
                continue;
            }

            $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, (int) $year, lock: $lock);

            $snapshots[] = [
                'year' => (int) $year,
                'days' => (float) $days,
                'pending_days' => (float) $balance->pending_days,
                'used_days' => (float) $balance->used_days,
                'remaining_days' => (float) $balance->remaining_days,
            ];
        }

        return $snapshots;
    }

    /**
     * Read-only allocation balance inspection. Never creates LeaveBalance rows.
     *
     * @return list<array{
     *     year: int,
     *     days: float,
     *     pending_days: float,
     *     used_days: float,
     *     remaining_days: float
     * }>
     *
     * @throws RuntimeException
     */
    public function inspectExistingAllocationBalances(LeaveRequest $leaveRequest, bool $lock = true): array
    {
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;
        $startDate = $leaveRequest->start_date?->toDateString();
        $endDate = $leaveRequest->end_date?->toDateString();

        if ($startDate === null || $endDate === null || $startDate === '' || $endDate === '') {
            throw new RuntimeException('Leave request dates are missing; cannot inspect leave balances.');
        }

        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            throw new RuntimeException('The leave type for this request is missing or does not belong to the request company.');
        }

        $snapshots = [];

        foreach ($this->daysByYear($startDate, $endDate) as $year => $days) {
            if ($days <= 0) {
                continue;
            }

            $balance = $this->existingBalance($companyId, $employeeId, (int) $leaveType->id, (int) $year, lock: $lock);

            if ($balance === null) {
                throw new RuntimeException(sprintf(
                    'Leave balance integrity check failed: missing %s balance for %d.',
                    $leaveType->name,
                    (int) $year,
                ));
            }

            $snapshots[] = [
                'year' => (int) $year,
                'days' => (float) $days,
                'pending_days' => (float) $balance->pending_days,
                'used_days' => (float) $balance->used_days,
                'remaining_days' => (float) $balance->remaining_days,
            ];
        }

        return $snapshots;
    }

    /**
     * Read-only pending ledger integrity check for administrative recovery actions.
     * Never creates LeaveBalance rows and never mutates entitled/carried/used/pending.
     *
     * For each affected employee + leave_type + year key, stored pending_days must
     * equal the aggregate pending allocation from real pending LeaveRequests.
     *
     * @return list<array{
     *     year: int,
     *     days: float,
     *     pending_days: float,
     *     used_days: float,
     *     remaining_days: float
     * }>
     *
     * @throws RuntimeException
     */
    public function assertPendingAllocationIntegrity(LeaveRequest $leaveRequest, bool $lock = true): array
    {
        $snapshots = $this->inspectExistingAllocationBalances($leaveRequest, lock: $lock);
        $companyId = (int) $leaveRequest->company_id;
        $employeeId = (int) $leaveRequest->employee_id;
        $leaveTypeId = (int) $leaveRequest->leave_type_id;

        foreach ($snapshots as $snapshot) {
            $year = (int) $snapshot['year'];
            $storedPending = (float) $snapshot['pending_days'];
            $expectedPending = $this->sumRequestDaysForYear(
                $companyId,
                $employeeId,
                $leaveTypeId,
                $year,
                'pending',
            );

            if (abs($storedPending - $expectedPending) > 0.0001) {
                throw new RuntimeException(sprintf(
                    'Leave balance pending ledger integrity check failed: company %d employee %d leave type %d year %d stored pending_days=%.4f expected=%.4f.',
                    $companyId,
                    $employeeId,
                    $leaveTypeId,
                    $year,
                    $storedPending,
                    $expectedPending,
                ));
            }
        }

        return $snapshots;
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
        $applied = 0;

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
            ->chunkById(100, function (Collection $employees) use ($companyId, $leaveTypes, $year, $previousYear, &$applied): void {
                foreach ($employees as $employee) {
                    foreach ($leaveTypes as $leaveType) {
                        if ($this->rolloverEmployeeLeaveType($companyId, (int) $employee->id, $leaveType, $year, $previousYear)) {
                            $applied++;
                        }
                    }
                }
            });

        return $applied;
    }

    /**
     * @param  (callable(array{
     *     company_id: int,
     *     employee_id: int,
     *     leave_type_id: int,
     *     year: int,
     *     message: string
     * }): void)|null  $onAnomaly
     */
    public function syncCompany(int $companyId, ?int $year = null, ?callable $onAnomaly = null): int
    {
        $businessYear = $this->businessYearForCompany($companyId);
        $years = $year !== null
            ? [$year]
            : $this->yearsWithLeaveActivity($companyId);

        if ($years === []) {
            $years = [$businessYear];
        }

        $synced = 0;
        $provisionYears = array_values(array_filter(
            $years,
            fn (int $targetYear): bool => $targetYear >= $businessYear,
        ));

        // Normal provisioning: active employees for current/future business years only.
        if ($provisionYears !== []) {
            Employee::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->select('id')
                ->chunkById(100, function (Collection $employees) use ($companyId, $provisionYears): void {
                    foreach ($employees as $employee) {
                        foreach ($provisionYears as $targetYear) {
                            $this->ensureEmployeeYear($companyId, (int) $employee->id, $targetYear);
                        }
                    }
                });
        }

        foreach ($this->discoverBalanceRepairKeys($companyId, $years) as $key) {
            $result = $this->repairBalanceKey(
                companyId: $companyId,
                employeeId: $key['employee_id'],
                leaveTypeId: $key['leave_type_id'],
                year: $key['year'],
                businessYear: $businessYear,
                onAnomaly: $onAnomaly,
            );

            if ($result === 'synced') {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * @param  (callable(array{
     *     company_id: int,
     *     employee_id: int,
     *     leave_type_id: int,
     *     year: int,
     *     message: string
     * }): void)|null  $onAnomaly
     */
    public function syncEmployeeYear(int $companyId, int $employeeId, int $year, ?callable $onAnomaly = null): int
    {
        $businessYear = $this->businessYearForCompany($companyId);
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first(['id', 'status']);

        if ($employee !== null && $employee->status === 'active' && $year >= $businessYear) {
            $this->ensureEmployeeYear($companyId, $employeeId, $year);
        }

        $synced = 0;
        $leaveTypeIds = $this->leaveTypeIdsForEmployeeYear($companyId, $employeeId, $year);

        foreach ($leaveTypeIds as $leaveTypeId) {
            $result = $this->repairBalanceKey(
                companyId: $companyId,
                employeeId: $employeeId,
                leaveTypeId: $leaveTypeId,
                year: $year,
                businessYear: $businessYear,
                onAnomaly: $onAnomaly,
            );

            if ($result === 'synced') {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Recalculate used/pending for one balance key under a short per-key transaction lock.
     * Does not overwrite entitled_days or carried_days (policy / HR entitlement fields).
     * Creates a balance only when $createIfMissing is true (normal provisioning).
     */
    public function synchronizeBalanceKey(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        bool $createIfMissing = true,
    ): void {
        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if ($leaveType === null) {
            return;
        }

        DB::transaction(function () use ($companyId, $employeeId, $leaveType, $leaveTypeId, $year, $createIfMissing): void {
            if ($createIfMissing) {
                $balance = $this->lockedBalance($companyId, $employeeId, $leaveType, $year, lock: true);
            } else {
                $balance = $this->existingBalance($companyId, $employeeId, (int) $leaveType->id, $year, lock: true);

                if ($balance === null) {
                    return;
                }
            }

            $balance->forceFill([
                'used_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'approved'),
                'pending_days' => $this->sumRequestDaysForYear($companyId, $employeeId, $leaveTypeId, $year, 'pending'),
            ])->save();
        });
    }

    /**
     * @param  list<int>  $years
     * @return list<array{employee_id: int, leave_type_id: int, year: int}>
     */
    private function discoverBalanceRepairKeys(int $companyId, array $years): array
    {
        $keys = [];

        LeaveBalance::query()
            ->where('company_id', $companyId)
            ->whereIn('year', $years)
            ->get(['employee_id', 'leave_type_id', 'year'])
            ->each(function (LeaveBalance $row) use (&$keys, $companyId): void {
                $key = $this->balanceLockKey($companyId, (int) $row->employee_id, (int) $row->leave_type_id, (int) $row->year);
                $keys[$key] = [
                    'employee_id' => (int) $row->employee_id,
                    'leave_type_id' => (int) $row->leave_type_id,
                    'year' => (int) $row->year,
                ];
            });

        LeaveRequest::query()
            ->where('company_id', $companyId)
            ->whereIn('status', ['approved', 'pending'])
            ->get(['employee_id', 'leave_type_id', 'start_date', 'end_date'])
            ->each(function (LeaveRequest $request) use (&$keys, $companyId, $years): void {
                $startYear = $request->start_date?->year;
                $endYear = $request->end_date?->year;

                if ($startYear === null || $endYear === null) {
                    return;
                }

                for ($year = min($startYear, $endYear); $year <= max($startYear, $endYear); $year++) {
                    if (! in_array($year, $years, true)) {
                        continue;
                    }

                    $key = $this->balanceLockKey(
                        $companyId,
                        (int) $request->employee_id,
                        (int) $request->leave_type_id,
                        $year,
                    );
                    $keys[$key] = [
                        'employee_id' => (int) $request->employee_id,
                        'leave_type_id' => (int) $request->leave_type_id,
                        'year' => $year,
                    ];
                }
            });

        return array_values($keys);
    }

    /**
     * @param  (callable(array{
     *     company_id: int,
     *     employee_id: int,
     *     leave_type_id: int,
     *     year: int,
     *     message: string
     * }): void)|null  $onAnomaly
     * @return 'synced'|'skipped'|'anomaly'
     */
    private function repairBalanceKey(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        int $businessYear,
        ?callable $onAnomaly = null,
    ): string {
        $existing = $this->existingBalance($companyId, $employeeId, $leaveTypeId, $year, lock: false);

        if ($existing !== null) {
            $this->synchronizeBalanceKey($companyId, $employeeId, $leaveTypeId, $year, createIfMissing: false);

            return 'synced';
        }

        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->first(['id', 'status']);
        $leaveType = LeaveType::query()
            ->where('company_id', $companyId)
            ->whereKey($leaveTypeId)
            ->first();

        if (
            $year >= $businessYear
            && $employee !== null
            && $employee->status === 'active'
            && $leaveType !== null
            && $leaveType->status === 'active'
        ) {
            $this->findOrCreateBalance($companyId, $employeeId, $leaveType, $year);
            $this->synchronizeBalanceKey($companyId, $employeeId, $leaveTypeId, $year, createIfMissing: false);

            return 'synced';
        }

        // Missing historical (or inactive) balance: do not invent entitlement from today's policy.
        $message = sprintf(
            'Leave balance repair anomaly: missing company %d employee %d leave type %d year %d balance; entitlement was not invented.',
            $companyId,
            $employeeId,
            $leaveTypeId,
            $year,
        );

        report(new RuntimeException($message));

        if ($onAnomaly !== null) {
            $onAnomaly([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'leave_type_id' => $leaveTypeId,
                'year' => $year,
                'message' => $message,
            ]);
        }

        return 'anomaly';
    }

    /**
     * @return list<int>
     */
    private function yearsWithLeaveActivity(int $companyId): array
    {
        $years = collect();

        LeaveRequest::query()
            ->where('company_id', $companyId)
            ->select(['id', 'start_date', 'end_date'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $requests) use ($years): void {
                foreach ($requests as $request) {
                    $startYear = $request->start_date?->year;
                    $endYear = $request->end_date?->year;

                    if ($startYear === null || $endYear === null) {
                        continue;
                    }

                    for ($year = min($startYear, $endYear); $year <= max($startYear, $endYear); $year++) {
                        $years->push($year);
                    }
                }
            });

        LeaveBalance::query()
            ->where('company_id', $companyId)
            ->distinct()
            ->pluck('year')
            ->each(fn ($year) => $years->push((int) $year));

        return $years
            ->push($this->businessYearForCompany($companyId))
            ->map(fn ($value): int => (int) $value)
            ->filter(fn (int $value): bool => $value > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Active leave types plus any type referenced by balances or overlapping requests
     * for this employee/year (including inactive types with historical usage).
     *
     * @return list<int>
     */
    private function leaveTypeIdsForEmployeeYear(int $companyId, int $employeeId, int $year): array
    {
        $activeIds = LeaveType::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->pluck('id');

        $balanceIds = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('year', $year)
            ->pluck('leave_type_id');

        $requestIds = LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('start_date', '<=', "{$year}-12-31")
            ->where('end_date', '>=', "{$year}-01-01")
            ->pluck('leave_type_id');

        return $activeIds
            ->merge($balanceIds)
            ->merge($requestIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
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
        return DB::transaction(function () use ($companyId, $employeeId, $leaveType, $year, $previousYear): bool {
            $previousBalance = LeaveBalance::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $previousYear)
                ->lockForUpdate()
                ->first();

            $balance = LeaveBalance::query()
                ->where('company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            $carriedDays = 0.0;

            if ($leaveType->carry_forward && $previousBalance !== null) {
                $remaining = max(0, (float) $previousBalance->remaining_days);
                $carriedDays = min($remaining, (float) $leaveType->max_carry_days);
            }

            if ($balance === null) {
                LeaveBalance::query()->create([
                    'company_id' => $companyId,
                    'employee_id' => $employeeId,
                    'leave_type_id' => $leaveType->id,
                    'year' => $year,
                    'entitled_days' => $leaveType->days_per_year,
                    'carried_days' => $carriedDays,
                    'used_days' => 0,
                    'pending_days' => 0,
                    'rollover_applied_at' => now(),
                ]);

                $this->synchronizeBalanceKey($companyId, $employeeId, (int) $leaveType->id, $year);

                return true;
            }

            if ($balance->rollover_applied_at !== null) {
                $this->synchronizeBalanceKey($companyId, $employeeId, (int) $leaveType->id, $year);

                return false;
            }

            // Provisional future-year row: apply carry once without wiping pending/used.
            $balance->forceFill([
                'carried_days' => $carriedDays,
                'rollover_applied_at' => now(),
            ])->save();

            $this->synchronizeBalanceKey($companyId, $employeeId, (int) $leaveType->id, $year);

            return true;
        });
    }

    private function businessYearForCompany(int $companyId): int
    {
        return (int) now(CompanyTimezone::forCompanyId($companyId))->year;
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

    private function existingBalance(
        int $companyId,
        int $employeeId,
        int $leaveTypeId,
        int $year,
        bool $lock,
    ): ?LeaveBalance {
        $query = LeaveBalance::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function lockedBalance(
        int $companyId,
        int $employeeId,
        LeaveType $leaveType,
        int $year,
        bool $lock,
    ): LeaveBalance {
        $balance = $this->existingBalance($companyId, $employeeId, (int) $leaveType->id, $year, lock: $lock);

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
