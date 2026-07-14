<?php

namespace App\Support\Contracts\Actions;

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;

final class UpsertEmployeeContract
{
    public function __construct(
        private readonly SyncContractSalaryComponentsFromContract $syncSalaryComponents,
        private readonly ApplyContractSalaryRevision $applySalaryRevision,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(
        int $companyId,
        Employee $employee,
        array $attributes,
        ?EmployeeContract $existing = null,
        ?int $createdBy = null,
        ?string $revisionEffectiveFrom = null,
        ?string $revisionReason = null,
    ): EmployeeContract {
        if (($attributes['status'] ?? $existing?->status ?? 'active') === 'active') {
            $this->deactivateOtherContracts($companyId, $employee->id, $existing?->id);
        }

        if ($existing === null) {
            $contract = EmployeeContract::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                ...$attributes,
            ]);

            $this->createRevisionIfNeeded(
                $contract,
                ApplyContractSalaryRevision::amountsFromContract($contract),
                $contract->start_date?->toDateString() ?? now()->toDateString(),
                $revisionReason ?? 'Initial contract salary',
                $createdBy,
            );

            return $contract->fresh();
        }

        $beforeAmounts = ApplyContractSalaryRevision::amountsFromContract($existing);
        $existing->update($attributes);
        $contract = $existing->fresh();
        $afterAmounts = ApplyContractSalaryRevision::amountsFromContract($contract);

        $hasRevisions = $contract->salaryRevisions()->exists();

        if (! $hasRevisions) {
            $this->createRevisionIfNeeded(
                $contract,
                $afterAmounts,
                $contract->start_date?->toDateString() ?? now()->toDateString(),
                $revisionReason ?? 'Initial contract salary',
                $createdBy,
            );

            return $contract->fresh();
        }

        if (ApplyContractSalaryRevision::salaryPackageChanged($beforeAmounts, $afterAmounts)) {
            $this->createRevisionIfNeeded(
                $contract,
                $afterAmounts,
                $revisionEffectiveFrom ?? now()->toDateString(),
                $revisionReason,
                $createdBy,
            );

            return $contract->fresh();
        }

        $this->syncSalaryComponents->handle($contract);

        return $contract;
    }

    /**
     * @param  array<string, float|int|string|null>  $amounts
     */
    private function createRevisionIfNeeded(
        EmployeeContract $contract,
        array $amounts,
        string $effectiveFrom,
        ?string $reason,
        ?int $createdBy,
    ): void {
        $hasPositiveAmount = collect($amounts)->contains(
            fn (mixed $amount): bool => $amount !== null && $amount !== '' && (float) $amount > 0,
        );

        if (! $hasPositiveAmount) {
            $this->syncSalaryComponents->handle($contract);

            return;
        }

        $this->applySalaryRevision->handle(
            $contract,
            $amounts,
            $effectiveFrom,
            $reason,
            $createdBy,
        );
    }

    private function deactivateOtherContracts(int $companyId, int $employeeId, ?int $exceptId = null): void
    {
        EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['status' => 'ended']);
    }
}
