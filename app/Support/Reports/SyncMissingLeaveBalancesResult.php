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
        public readonly int $year,
    ) {}

    public function nothingToCreate(): bool
    {
        return $this->newBalanceRecordsCreated === 0;
    }

    /**
     * @return array{
     *     eligible_employees_checked: int,
     *     employees_with_new_balances: int,
     *     new_balance_records_created: int,
     *     already_existing_balances: int,
     *     skipped_or_anomalies: int,
     *     year: int,
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
            'year' => $this->year,
            'nothing_to_create' => $this->nothingToCreate(),
        ];
    }
}
