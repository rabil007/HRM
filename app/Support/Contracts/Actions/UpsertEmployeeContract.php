<?php

namespace App\Support\Contracts\Actions;

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        if ($this->participatesInCrewPayrollSource($attributes, $existing)) {
            return DB::transaction(function () use (
                $companyId,
                $employee,
                $attributes,
                $existing,
                $createdBy,
                $revisionEffectiveFrom,
                $revisionReason,
            ): EmployeeContract {
                $this->lockEmployeeForContract($companyId, (int) $employee->id);

                return $this->performUpsert(
                    $companyId,
                    $employee,
                    $attributes,
                    $existing,
                    $createdBy,
                    $revisionEffectiveFrom,
                    $revisionReason,
                );
            });
        }

        return $this->performUpsert(
            $companyId,
            $employee,
            $attributes,
            $existing,
            $createdBy,
            $revisionEffectiveFrom,
            $revisionReason,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function performUpsert(
        int $companyId,
        Employee $employee,
        array $attributes,
        ?EmployeeContract $existing = null,
        ?int $createdBy = null,
        ?string $revisionEffectiveFrom = null,
        ?string $revisionReason = null,
    ): EmployeeContract {
        $becomesActive = ($attributes['status'] ?? $existing?->status ?? 'active') === 'active';

        if ($becomesActive) {
            $newStartDate = $attributes['start_date'] ?? $existing?->start_date?->toDateString();

            $this->deactivateOtherContracts($companyId, $employee->id, $existing?->id, $newStartDate);
        }

        if ($existing === null) {
            $contract = EmployeeContract::query()->create([
                'company_id' => $companyId,
                'employee_id' => $employee->id,
                ...$attributes,
            ]);

            $this->syncEmployeeCurrentVisaCompany($employee, $contract);

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

        $this->syncEmployeeCurrentVisaCompany($employee, $contract);

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

    /**
     * Ends any other non-deleted active contract for the employee.
     *
     * Different Company Visa Types never make overlapping employment
     * contracts safe: the payroll resolver still selects contracts by
     * date range, not by status. When another active contract's date
     * range would otherwise overlap the new/updated contract's start
     * date, its effective end date is capped to one day before the new
     * start date so no two non-deleted contracts cover the same work
     * date. When the new contract starts on or before the other
     * contract's own start date, the dates cannot be safely corrected
     * automatically and the caller must use the visa company transfer
     * workflow or choose a different date.
     */
    private function deactivateOtherContracts(
        int $companyId,
        int $employeeId,
        ?int $exceptId,
        ?string $newStartDate,
    ): void {
        /** @var Collection<int, EmployeeContract> $others */
        $others = EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('status', 'active')
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->get();

        if ($others->isEmpty()) {
            return;
        }

        $newStart = $newStartDate !== null ? Carbon::parse($newStartDate)->startOfDay() : null;

        foreach ($others as $other) {
            $updates = ['status' => 'ended'];

            if ($newStart !== null) {
                $otherEnd = $other->end_date;
                $overlaps = $otherEnd === null || $otherEnd->greaterThanOrEqualTo($newStart);

                if ($overlaps) {
                    $otherStart = $other->start_date;
                    $cappedEnd = $newStart->copy()->subDay();

                    if ($otherStart !== null && $cappedEnd->lessThan($otherStart->copy()->startOfDay())) {
                        throw ValidationException::withMessages([
                            'start_date' => "Contract #{$other->id} is already active on or after this start date. Use the visa company transfer workflow, or choose a start date after {$otherStart->toDateString()}.",
                        ]);
                    }

                    $updates['end_date'] = $cappedEnd->toDateString();
                }
            }

            $other->update($updates);
        }
    }

    /**
     * The employee's current Company Visa Type mirrors whichever contract
     * is now the active one. Ended or historical contracts must never
     * update this value.
     */
    private function syncEmployeeCurrentVisaCompany(Employee $employee, EmployeeContract $contract): void
    {
        if ($contract->status !== 'active' || $contract->company_visa_type_id === null) {
            return;
        }

        if ((int) ($employee->company_visa_type_id ?? 0) === (int) $contract->company_visa_type_id) {
            return;
        }

        $employee->update(['company_visa_type_id' => $contract->company_visa_type_id]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function participatesInCrewPayrollSource(array $attributes, ?EmployeeContract $existing): bool
    {
        $rawCategory = $attributes['payroll_category'] ?? $existing?->payroll_category ?? null;
        $category = $rawCategory instanceof PayrollCategory
            ? $rawCategory
            : PayrollCategory::tryFrom((string) ($rawCategory ?? ''));

        return $category === PayrollCategory::Crew
            || $existing?->payroll_category === PayrollCategory::Crew;
    }

    private function lockEmployeeForContract(int $companyId, int $employeeId): Employee
    {
        $employee = Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->lockForUpdate()
            ->first();

        if ($employee === null) {
            throw ValidationException::withMessages([
                'employee' => 'Employee not found in this company.',
            ]);
        }

        return $employee;
    }
}
