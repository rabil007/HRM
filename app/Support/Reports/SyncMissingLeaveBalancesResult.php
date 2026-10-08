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
        public readonly int $skippedAnnualMissingHireDate,
        public readonly int $skippedAnnualNotYetJoined,
        public readonly int $activeLeaveTypesCount,
        public readonly int $year,
    ) {}

    public function expectedAnnualSkips(): int
    {
        return $this->skippedAnnualMissingHireDate + $this->skippedAnnualNotYetJoined;
    }

    /**
     * @return 'no_active_leave_types'|'no_eligible_employees'|'nothing_missing'|'expected_skips_only'|'anomalies_only'|'partial_success'|'success'
     */
    public function messageVariant(): string
    {
        if ($this->activeLeaveTypesCount === 0) {
            return 'no_active_leave_types';
        }

        if ($this->eligibleEmployeesChecked === 0) {
            return 'no_eligible_employees';
        }

        if (
            $this->newBalanceRecordsCreated === 0
            && $this->skippedOrAnomalies === 0
            && $this->expectedAnnualSkips() === 0
        ) {
            return 'nothing_missing';
        }

        if ($this->newBalanceRecordsCreated === 0 && $this->skippedOrAnomalies > 0 && $this->expectedAnnualSkips() === 0) {
            return 'anomalies_only';
        }

        if ($this->newBalanceRecordsCreated === 0 && $this->skippedOrAnomalies === 0 && $this->expectedAnnualSkips() > 0) {
            return 'expected_skips_only';
        }

        if ($this->newBalanceRecordsCreated > 0 && ($this->skippedOrAnomalies > 0 || $this->expectedAnnualSkips() > 0)) {
            return 'partial_success';
        }

        return 'success';
    }

    public function nothingToCreate(): bool
    {
        return $this->messageVariant() === 'nothing_missing';
    }

    public function summaryMessage(): string
    {
        if ($this->activeLeaveTypesCount === 0) {
            return 'No active leave types are configured for this company.';
        }

        if ($this->eligibleEmployeesChecked === 0) {
            return 'No eligible employees were checked.';
        }

        $parts = ['Sync completed.'];

        if ($this->newBalanceRecordsCreated > 0) {
            $parts[] = sprintf(
                '%d new balance%s created.',
                $this->newBalanceRecordsCreated,
                $this->newBalanceRecordsCreated === 1 ? '' : 's',
            );
        }

        if ($this->alreadyExistingBalances > 0) {
            $parts[] = sprintf(
                '%d existing balance%s preserved.',
                $this->alreadyExistingBalances,
                $this->alreadyExistingBalances === 1 ? '' : 's',
            );
        }

        if ($this->skippedAnnualMissingHireDate > 0) {
            $parts[] = sprintf(
                '%d annual leave allocation%s skipped because employee hire dates are missing.',
                $this->skippedAnnualMissingHireDate,
                $this->skippedAnnualMissingHireDate === 1 ? '' : 's',
            );
        }

        if ($this->skippedAnnualNotYetJoined > 0) {
            $parts[] = sprintf(
                '%d annual leave allocation%s skipped because employees have not yet joined.',
                $this->skippedAnnualNotYetJoined,
                $this->skippedAnnualNotYetJoined === 1 ? '' : 's',
            );
        }

        if ($this->skippedOrAnomalies > 0) {
            $parts[] = sprintf(
                '%d record%s require attention and were not created.',
                $this->skippedOrAnomalies,
                $this->skippedOrAnomalies === 1 ? '' : 's',
            );
        }

        if (
            $this->newBalanceRecordsCreated === 0
            && $this->skippedOrAnomalies === 0
            && $this->expectedAnnualSkips() === 0
        ) {
            return 'All eligible employees already have their current-year leave balances.';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{
     *     eligible_employees_checked: int,
     *     employees_with_new_balances: int,
     *     new_balance_records_created: int,
     *     already_existing_balances: int,
     *     skipped_or_anomalies: int,
     *     skipped_annual_missing_hire_date: int,
     *     skipped_annual_not_yet_joined: int,
     *     active_leave_types_count: int,
     *     year: int,
     *     message_variant: string,
     *     nothing_to_create: bool,
     *     summary_message: string,
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
            'skipped_annual_missing_hire_date' => $this->skippedAnnualMissingHireDate,
            'skipped_annual_not_yet_joined' => $this->skippedAnnualNotYetJoined,
            'active_leave_types_count' => $this->activeLeaveTypesCount,
            'year' => $this->year,
            'message_variant' => $this->messageVariant(),
            'nothing_to_create' => $this->nothingToCreate(),
            'summary_message' => $this->summaryMessage(),
        ];
    }
}
