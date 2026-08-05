<?php

namespace App\Support\Payroll;

use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\PayrollWorkPeriodClassification;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersistPayrollWorkAllocations
{
    /**
     * Replace reserved allocations for a payroll record with the provided day plan.
     * Never deletes approved/paid allocations.
     *
     * @param  list<array<string, mixed>>  $days
     */
    public function replaceForRecord(
        PayrollPeriod $period,
        PayrollRecord $record,
        array $days,
        ?int $crewTimesheetId = null,
    ): void {
        DB::transaction(function () use ($period, $record, $days, $crewTimesheetId): void {
            $this->releaseReservedForRecord($record);

            if ($days === []) {
                return;
            }

            $now = now();
            $rows = [];

            foreach ($days as $day) {
                $workDate = (string) $day['work_date'];
                $rows[] = [
                    'company_id' => (int) $period->company_id,
                    'employee_id' => (int) $record->employee_id,
                    'payroll_period_id' => (int) $period->id,
                    'payroll_record_id' => (int) $record->id,
                    'crew_timesheet_id' => $crewTimesheetId,
                    'crew_timesheet_segment_id' => $day['crew_timesheet_segment_id'] ?? null,
                    'work_date' => $workDate,
                    'pay_category' => $day['pay_category'],
                    'period_classification' => $day['period_classification'],
                    'status' => PayrollWorkAllocationStatus::Reserved->value,
                    'source' => $day['source'] ?? null,
                    'crew_assignment_id' => $day['crew_assignment_id'] ?? null,
                    'crew_assignment_phase_id' => $day['crew_assignment_phase_id'] ?? null,
                    'contract_id' => $day['contract_id'],
                    'salary_revision_id' => $day['salary_revision_id'],
                    'basic_daily_rate' => $day['basic_daily_rate'],
                    'site_allowance_daily_rate' => $day['site_allowance_daily_rate'],
                    'supplementary_allowance_daily_rate' => $day['supplementary_allowance_daily_rate'],
                    'basic_amount' => $day['basic_amount'],
                    'site_allowance_amount' => $day['site_allowance_amount'],
                    'supplementary_allowance_amount' => $day['supplementary_allowance_amount'],
                    'total_amount' => $day['total_amount'],
                    'approved_at' => null,
                    'paid_at' => null,
                    'reversed_at' => null,
                    'reversal_reason' => null,
                    'active_allocation_key' => sprintf(
                        '%d:%d:%s',
                        (int) $period->company_id,
                        (int) $record->employee_id,
                        $workDate,
                    ),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            try {
                PayrollWorkAllocation::query()->insert($rows);
            } catch (QueryException $exception) {
                if ($this->isUniqueViolation($exception)) {
                    throw ValidationException::withMessages([
                        'employee_id' => 'One or more work dates are already allocated to another payroll record.',
                    ]);
                }

                throw $exception;
            }

            activity()
                ->performedOn($record)
                ->withProperties([
                    'event' => 'payroll_work_allocations_created',
                    'company_id' => (int) $period->company_id,
                    'payroll_period_id' => (int) $period->id,
                    'payroll_record_id' => (int) $record->id,
                    'employee_id' => (int) $record->employee_id,
                    'allocation_count' => count($rows),
                    'prior_period_count' => collect($days)
                        ->where('period_classification', PayrollWorkPeriodClassification::Prior->value)
                        ->count(),
                ])
                ->log('Payroll work allocations created');
        });
    }

    public function releaseReservedForRecord(PayrollRecord $record): void
    {
        $deleted = PayrollWorkAllocation::query()
            ->where('company_id', (int) $record->company_id)
            ->where('payroll_record_id', (int) $record->id)
            ->where('status', PayrollWorkAllocationStatus::Reserved->value)
            ->delete();

        if ($deleted > 0) {
            activity()
                ->performedOn($record)
                ->withProperties([
                    'event' => 'payroll_work_allocations_released',
                    'company_id' => (int) $record->company_id,
                    'payroll_period_id' => (int) $record->period_id,
                    'payroll_record_id' => (int) $record->id,
                    'employee_id' => (int) $record->employee_id,
                    'released_count' => $deleted,
                ])
                ->log('Payroll work allocations released');
        }
    }

    public function releaseReservedForPeriod(PayrollPeriod $period): void
    {
        $deleted = PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->where('status', PayrollWorkAllocationStatus::Reserved->value)
            ->delete();

        if ($deleted > 0) {
            activity()
                ->performedOn($period)
                ->withProperties([
                    'event' => 'payroll_work_allocations_released',
                    'company_id' => (int) $period->company_id,
                    'payroll_period_id' => (int) $period->id,
                    'released_count' => $deleted,
                ])
                ->log('Payroll period work allocations released');
        }
    }

    /**
     * @param  list<int>  $employeeIds
     */
    public function releaseReservedForEmployees(PayrollPeriod $period, array $employeeIds): void
    {
        if ($employeeIds === []) {
            return;
        }

        PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', PayrollWorkAllocationStatus::Reserved->value)
            ->delete();
    }

    public function reverseForPeriod(PayrollPeriod $period, string $reason): int
    {
        $now = now();

        $updated = PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved->value,
                PayrollWorkAllocationStatus::Paid->value,
            ])
            ->update([
                'status' => PayrollWorkAllocationStatus::Reversed->value,
                'active_allocation_key' => null,
                'reversed_at' => $now,
                'reversal_reason' => $reason,
                'updated_at' => $now,
            ]);

        if ($updated > 0) {
            activity()
                ->performedOn($period)
                ->withProperties([
                    'event' => 'payroll_work_allocations_reversed',
                    'company_id' => (int) $period->company_id,
                    'payroll_period_id' => (int) $period->id,
                    'reversed_count' => $updated,
                    'reason' => $reason,
                ])
                ->log('Payroll work allocations reversed');
        }

        return $updated;
    }

    public function transitionToApproved(PayrollPeriod $period): int
    {
        $now = now();

        return PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->where('status', PayrollWorkAllocationStatus::Reserved->value)
            ->update([
                'status' => PayrollWorkAllocationStatus::Approved->value,
                'approved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    public function transitionToPaid(PayrollPeriod $period): int
    {
        $now = now();

        return PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->where('status', PayrollWorkAllocationStatus::Approved->value)
            ->update([
                'status' => PayrollWorkAllocationStatus::Paid->value,
                'paid_at' => $now,
                'updated_at' => $now,
            ]);
    }

    /**
     * Approved → Processing revert: return approved day locks to reserved without releasing dates.
     */
    public function transitionApprovedBackToReserved(PayrollPeriod $period): int
    {
        $now = now();

        return PayrollWorkAllocation::query()
            ->where('company_id', (int) $period->company_id)
            ->where('payroll_period_id', (int) $period->id)
            ->where('status', PayrollWorkAllocationStatus::Approved->value)
            ->update([
                'status' => PayrollWorkAllocationStatus::Reserved->value,
                'approved_at' => null,
                'updated_at' => $now,
            ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $errorInfo = $exception->errorInfo[1] ?? null;

        return $errorInfo === 1062 || str_contains(strtolower($exception->getMessage()), 'unique');
    }
}
