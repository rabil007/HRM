<?php

namespace App\Support\Contracts;

use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Models\ContractSalaryRevision;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Validation\ValidationException;

final class AssertContractSalaryRevisionEditable
{
    public const LOCKED_MESSAGE = 'This salary revision was used by approved or paid payroll. Use the authorized payroll correction workflow instead of changing the historical rate basis.';

    public function assertCanMutate(ContractSalaryRevision $revision): void
    {
        if ($this->isLockedByFinalizedPayroll($revision)) {
            throw ValidationException::withMessages([
                'salary_revision' => self::LOCKED_MESSAGE,
            ]);
        }
    }

    public function isLockedByFinalizedPayroll(ContractSalaryRevision $revision): bool
    {
        $hasFinalizedAllocations = PayrollWorkAllocation::query()
            ->where('company_id', $revision->company_id)
            ->where('salary_revision_id', $revision->id)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved->value,
                PayrollWorkAllocationStatus::Paid->value,
            ])
            ->exists();

        if ($hasFinalizedAllocations) {
            return true;
        }

        if (! (bool) $revision->apply_to_payroll) {
            return false;
        }

        $from = $revision->payroll_effective_from?->toDateString()
            ?? $revision->effective_from?->toDateString();

        if ($from === null) {
            return false;
        }

        $nextFrom = ContractSalaryRevision::query()
            ->where('company_id', $revision->company_id)
            ->where('contract_id', $revision->contract_id)
            ->where('apply_to_payroll', true)
            ->whereKeyNot($revision->id)
            ->whereNotNull('payroll_effective_from')
            ->whereDate('payroll_effective_from', '>', $from)
            ->orderBy('payroll_effective_from')
            ->value('payroll_effective_from');

        return PayrollRecord::query()
            ->where('company_id', $revision->company_id)
            ->where('employee_id', $revision->employee_id)
            ->whereHas('period', function ($query) use ($from, $nextFrom): void {
                $query
                    ->whereIn('status', [
                        PayrollPeriodStatus::Approved->value,
                        PayrollPeriodStatus::Paid->value,
                    ])
                    ->whereDate('start_date', '>=', $from)
                    ->when(
                        $nextFrom !== null,
                        fn ($periodQuery) => $periodQuery->whereDate('start_date', '<', $nextFrom),
                    );
            })
            ->exists();
    }
}
