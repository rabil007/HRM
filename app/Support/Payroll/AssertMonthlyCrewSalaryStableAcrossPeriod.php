<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Models\ContractSalaryComponent;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Monthly Crew resolves one package for the whole period. Off-cycle periods that
 * cross a payroll-applicable salary revision boundary must be blocked rather than
 * silently applying the period-start package to later months.
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

        if ($start->toDateString() === $end->toDateString()) {
            return;
        }

        $startResolved = $this->resolveEffectiveComponents->resolve($contract, $start);
        $endResolved = $this->resolveEffectiveComponents->resolve($contract, $end);

        if ($startResolved['issue'] !== null || $endResolved['issue'] !== null) {
            // Existing missing-revision handling remains authoritative.
            return;
        }

        $startRevisionId = $startResolved['revision']?->id;
        $endRevisionId = $endResolved['revision']?->id;

        if ($startRevisionId === $endRevisionId) {
            return;
        }

        $startFingerprint = $this->fingerprint($startResolved['components']);
        $endFingerprint = $this->fingerprint($endResolved['components']);

        if ($startFingerprint === $endFingerprint) {
            return;
        }

        throw ValidationException::withMessages([
            'salary_revision' => self::BOUNDARY_MESSAGE,
        ]);
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function fingerprint($components): string
    {
        return $components
            ->mapWithKeys(function ($component): array {
                $code = $component->component_code instanceof SalaryComponentCode
                    ? $component->component_code->value
                    : (string) $component->component_code;

                return [$code => number_format((float) $component->amount, 2, '.', '')];
            })
            ->sortKeys()
            ->toJson();
    }
}
