<?php

namespace App\Support\Contracts;

use App\Enums\PayrollPeriodStatus;
use App\Enums\PayrollWorkAllocationStatus;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Models\PayrollRecord;
use App\Models\PayrollWorkAllocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class AssertContractSalaryRevisionEditable
{
    public const LOCKED_MESSAGE = 'This salary revision would change rates used by approved or paid payroll. Use the authorized payroll correction workflow.';

    /**
     * @param  list<array{component_code: SalaryComponentCode|string, amount: float|int|string}>  $lines
     */
    public function assertCreateWouldNotAlterFinalizedPayroll(
        EmployeeContract $contract,
        bool $applyToPayroll,
        ?string $payrollEffectiveFrom,
        array $lines,
        int $proposedVersion,
    ): void {
        if (! $applyToPayroll || $payrollEffectiveFrom === null) {
            return;
        }

        $before = $this->currentRevisions($contract);
        $after = $before->values();

        $proposed = $this->makeVirtualRevision(
            $contract,
            $applyToPayroll,
            $payrollEffectiveFrom,
            $lines,
            $proposedVersion,
        );
        $after->push($proposed);

        $this->assertProjectedHistorySafe($contract, $before, $after);
    }

    /**
     * @param  list<array{component_code: SalaryComponentCode|string, amount: float|int|string}>  $lines
     */
    public function assertUpdateWouldNotAlterFinalizedPayroll(
        EmployeeContract $contract,
        ContractSalaryRevision $revision,
        bool $applyToPayroll,
        ?string $payrollEffectiveFrom,
        array $lines,
    ): void {
        $before = $this->currentRevisions($contract);
        $after = $before
            ->map(function (ContractSalaryRevision $item) use (
                $contract,
                $revision,
                $applyToPayroll,
                $payrollEffectiveFrom,
                $lines,
            ): ContractSalaryRevision {
                if ((int) $item->id !== (int) $revision->id) {
                    return $item;
                }

                $updated = $item->replicate();
                $updated->id = $item->id;
                $updated->version = $item->version;
                $updated->apply_to_payroll = $applyToPayroll;
                $updated->payroll_effective_from = $payrollEffectiveFrom;
                $updated->effective_from = $item->effective_from;
                $updated->setRelation('lines', $this->linesCollection($contract, $lines));

                return $updated;
            })
            ->values();

        $this->assertProjectedHistorySafe($contract, $before, $after);
    }

    public function assertDeleteWouldNotAlterFinalizedPayroll(
        EmployeeContract $contract,
        ContractSalaryRevision $revision,
    ): void {
        $before = $this->currentRevisions($contract);
        $after = $before
            ->reject(fn (ContractSalaryRevision $item): bool => (int) $item->id === (int) $revision->id)
            ->values();

        $this->assertProjectedHistorySafe($contract, $before, $after);
    }

    /**
     * @deprecated Use the proposed-change assertions. Kept for call sites that only
     *             know the current revision identity.
     */
    public function assertCanMutate(ContractSalaryRevision $revision): void
    {
        $contract = $revision->relationLoaded('contract')
            ? $revision->contract
            : EmployeeContract::query()
                ->whereKey($revision->contract_id)
                ->where('company_id', $revision->company_id)
                ->firstOrFail();

        $this->assertDeleteWouldNotAlterFinalizedPayroll($contract, $revision);
    }

    /**
     * @param  Collection<int, ContractSalaryRevision>  $before
     * @param  Collection<int, ContractSalaryRevision>  $after
     */
    private function assertProjectedHistorySafe(
        EmployeeContract $contract,
        Collection $before,
        Collection $after,
    ): void {
        $dates = $this->finalizedPayrollDates($contract);

        if ($dates === []) {
            return;
        }

        foreach ($dates as $date) {
            $beforeFingerprint = $this->packageFingerprint(
                $this->resolveApplicable($before, $date),
            );
            $afterFingerprint = $this->packageFingerprint(
                $this->resolveApplicable($after, $date),
            );

            if ($beforeFingerprint !== $afterFingerprint) {
                throw ValidationException::withMessages([
                    'salary_revision' => self::LOCKED_MESSAGE,
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function finalizedPayrollDates(EmployeeContract $contract): array
    {
        $allocationDates = PayrollWorkAllocation::query()
            ->where('company_id', $contract->company_id)
            ->where('employee_id', $contract->employee_id)
            ->where('contract_id', $contract->id)
            ->whereIn('status', [
                PayrollWorkAllocationStatus::Approved->value,
                PayrollWorkAllocationStatus::Paid->value,
            ])
            ->pluck('work_date')
            ->map(fn ($date): string => (string) $date)
            ->all();

        $periodStartDates = PayrollRecord::query()
            ->where('company_id', $contract->company_id)
            ->where('employee_id', $contract->employee_id)
            ->where('contract_id', $contract->id)
            ->whereNull('deleted_at')
            ->whereHas('period', function ($query): void {
                $query->whereIn('status', [
                    PayrollPeriodStatus::Approved->value,
                    PayrollPeriodStatus::Paid->value,
                ]);
            })
            ->with('period:id,start_date,end_date')
            ->get()
            ->flatMap(function (PayrollRecord $record): array {
                $start = $record->period?->start_date?->toDateString();
                $end = $record->period?->end_date?->toDateString();

                if ($start === null) {
                    return [];
                }

                $dates = [$start];

                if ($end !== null && $end !== $start) {
                    $dates[] = $end;
                }

                return $dates;
            })
            ->all();

        return collect([...$allocationDates, ...$periodStartDates])
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ContractSalaryRevision>
     */
    private function currentRevisions(EmployeeContract $contract): Collection
    {
        return ContractSalaryRevision::query()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->with('lines')
            ->orderBy('version')
            ->get();
    }

    /**
     * @param  Collection<int, ContractSalaryRevision>  $revisions
     */
    private function resolveApplicable(Collection $revisions, string $asOfDate): ?ContractSalaryRevision
    {
        return $revisions
            ->filter(function (ContractSalaryRevision $item) use ($asOfDate): bool {
                if (! $item->appliesToPayroll()) {
                    return false;
                }

                $payrollFrom = $item->payrollEffectiveDateString();

                return $payrollFrom !== null && $payrollFrom <= $asOfDate;
            })
            ->sortByDesc(fn (ContractSalaryRevision $item): array => [
                $item->payrollEffectiveDateString() ?? '',
                $item->version ?? 0,
            ])
            ->first();
    }

    private function packageFingerprint(?ContractSalaryRevision $revision): string
    {
        if ($revision === null) {
            return 'none';
        }

        if (! $revision->relationLoaded('lines')) {
            $revision->load('lines');
        }

        return $revision->lines
            ->mapWithKeys(function (ContractSalaryRevisionLine $line): array {
                $code = $line->component_code instanceof SalaryComponentCode
                    ? $line->component_code->value
                    : (string) $line->component_code;

                return [$code => number_format((float) $line->amount, 2, '.', '')];
            })
            ->sortKeys()
            ->toJson();
    }

    /**
     * @param  list<array{component_code: SalaryComponentCode|string, amount: float|int|string}>  $lines
     */
    private function makeVirtualRevision(
        EmployeeContract $contract,
        bool $applyToPayroll,
        string $payrollEffectiveFrom,
        array $lines,
        int $proposedVersion,
    ): ContractSalaryRevision {
        $revision = new ContractSalaryRevision([
            'company_id' => $contract->company_id,
            'contract_id' => $contract->id,
            'employee_id' => $contract->employee_id,
            'version' => $proposedVersion,
            'effective_from' => $payrollEffectiveFrom,
            'apply_to_payroll' => $applyToPayroll,
            'payroll_effective_from' => $payrollEffectiveFrom,
        ]);
        $revision->id = PHP_INT_MAX;
        $revision->setRelation('lines', $this->linesCollection($contract, $lines));

        return $revision;
    }

    /**
     * @param  list<array{component_code: SalaryComponentCode|string, amount: float|int|string}>  $lines
     * @return Collection<int, ContractSalaryRevisionLine>
     */
    private function linesCollection(EmployeeContract $contract, array $lines): Collection
    {
        return collect($lines)->map(function (array $line) use ($contract): ContractSalaryRevisionLine {
            $code = $line['component_code'] instanceof SalaryComponentCode
                ? $line['component_code']
                : SalaryComponentCode::from((string) $line['component_code']);

            return new ContractSalaryRevisionLine([
                'company_id' => $contract->company_id,
                'component_code' => $code,
                'component_name' => $line['component_name'] ?? $code->label(),
                'rate_type' => $line['rate_type'] ?? null,
                'amount' => round((float) $line['amount'], 2),
            ]);
        })->values();
    }
}
