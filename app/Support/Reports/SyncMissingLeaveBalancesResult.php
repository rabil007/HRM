<?php

namespace App\Support\Reports;

final class SyncMissingLeaveBalancesResult
{
    public function __construct(
        public readonly int $eligibleEmployeesChecked,
        public readonly int $employeesWithNewBalances,
        public readonly int $newBalanceRecordsCreated,
        public readonly int $alreadyExistingBalances,
        public readonly int $skippedOrAnomalies,
        public readonly int $activeLeaveTypesCount,
        public readonly int $year,
    ) {}

    /**
     * @return 'no_active_leave_types'|'no_eligible_employees'|'nothing_missing'|'anomalies_only'|'partial_success'|'success'
     */
    public function messageVariant(): string
    {
        if ($this->activeLeaveTypesCount === 0) {
            return 'no_active_leave_types';
        }

        if ($this->eligibleEmployeesChecked === 0) {
            return 'no_eligible_employees';
        }

        if ($this->newBalanceRecordsCreated === 0 && $this->skippedOrAnomalies === 0) {
            return 'nothing_missing';
        }

        if ($this->newBalanceRecordsCreated === 0 && $this->skippedOrAnomalies > 0) {
            return 'anomalies_only';
        }

        if ($this->newBalanceRecordsCreated > 0 && $this->skippedOrAnomalies > 0) {
            return 'partial_success';
        }

        return 'success';
    }

    public function nothingToCreate(): bool
    {
        return $this->messageVariant() === 'nothing_missing';
    }

    /**
     * @return array{
     *     eligible_employees_checked: int,
     *     employees_with_new_balances: int,
     *     new_balance_records_created: int,
     *     already_existing_balances: int,
     *     skipped_or_anomalies: int,
     *     active_leave_types_count: int,
     *     year: int,
     *     message_variant: string,
     *     nothing_to_create: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'eligible_employees_checked' => $this->eligibleEmployeesChecked,
            'employees_with_new_balances' => $this->employeesWithNewBalances,
            'new_balance_records_created' => $this->newBalanceRecordsCreated,
            'already_existing_balances' => $this->alreadyExistingBalances,
            'skipped_or_anomalies' => $this->skippedOrAnomalies,
            'active_leave_types_count' => $this->activeLeaveTypesCount,
            'year' => $this->year,
            'message_variant' => $this->messageVariant(),
            'nothing_to_create' => $this->nothingToCreate(),
        ];
    }
}
