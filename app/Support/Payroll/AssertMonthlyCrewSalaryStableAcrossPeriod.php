<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Monthly Crew resolves one package for the whole period. Off-cycle periods that
 * cross any payroll-applicable salary revision boundary with a different payable
 * package must be blocked rather than silently applying the period-start package.
 */
final class AssertMonthlyCrewSalaryStableAcrossPeriod
{
    public const BOUNDARY_MESSAGE = 'This payroll period crosses a salary revision boundary. Split the period into ranges that each use one payroll-applicable salary package, or use the authorized payroll correction workflow.';

    public function __construct(
        private readonly ResolveEffectiveContractSalaryComponents $resolveEffectiveComponents,
    ) {}

    public function assert(EmployeeContract $contract, PayrollPeriod $period): void
    {
        if (($contract->payroll_category ?? null) !== PayrollCategory::Crew) {
            return;
        }

        if ($contract->resolvedSalaryStructure() !== ContractSalaryStructure::Monthly) {
            return;
        }

        $start = $period->start_date;
        $end = $period->end_date;

        if ($start === null || $end === null) {
            return;
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        if ($startDate === $endDate) {
            return;
        }

        $startResolved = $this->resolveEffectiveComponents->resolve($contract, $start);

        if ($startResolved['issue'] !== null) {
            // Existing missing-revision handling remains authoritative.
            return;
        }

        $startFingerprint = $this->fingerprint($startResolved['components']);

        foreach ($this->checkpointDates($contract, $startDate, $endDate) as $checkpoint) {
            $resolved = $this->resolveEffectiveComponents->resolve(
                $contract,
                CarbonImmutable::parse($checkpoint),
            );

            if ($resolved['issue'] !== null) {
                return;
            }

            if ($this->fingerprint($resolved['components']) !== $startFingerprint) {
                throw ValidationException::withMessages([
                    'salary_revision' => self::BOUNDARY_MESSAGE,
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function checkpointDates(EmployeeContract $contract, string $startDate, string $endDate): array
    {
        $revisionHistory = $contract->relationLoaded('salaryRevisionHistory')
            ? $contract->salaryRevisionHistory
            : $contract->salaryRevisionHistory()->with('lines')->get();

        $boundaryDates = $revisionHistory
            ->reject(fn (ContractSalaryRevision $item): bool => $item->trashed())
            ->filter(fn (ContractSalaryRevision $item): bool => $item->appliesToPayroll())
            ->map(fn (ContractSalaryRevision $item): ?string => $item->payrollEffectiveDateString())
            ->filter(function (?string $date) use ($startDate, $endDate): bool {
                return $date !== null
                    && $date > $startDate
                    && $date <= $endDate;
            })
            ->all();

        return collect([$startDate, $endDate, ...$boundaryDates])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function fingerprint(Collection $components): string
    {
        return $components
            ->mapWithKeys(function (ContractSalaryComponent $component): array {
                $code = $component->component_code instanceof SalaryComponentCode
                    ? $component->component_code->value
                    : (string) $component->component_code;

                return [$code => number_format((float) $component->amount, 2, '.', '')];
            })
            ->sortKeys()
            ->toJson();
    }
}
