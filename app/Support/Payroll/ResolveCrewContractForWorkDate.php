<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\ContractSalaryRevision;
use App\Models\EmployeeContract;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the exact Crew Daily contract covering a single work date.
 *
 * Unlike period-level resolution, this never falls back to the latest active
 * contract when no contract covers the work date.
 */
final class ResolveCrewContractForWorkDate
{
    /**
     * @param  Collection<int, EmployeeContract>|null  $contracts
     * @return array{contract: EmployeeContract, issue: null}|array{contract: null, issue: array{code: string, message: string}}
     */
    public function resolve(
        int $companyId,
        int $employeeId,
        CarbonInterface|string $workDate,
        ?Collection $contracts = null,
    ): array {
        $date = CarbonImmutable::parse($workDate)->toDateString();

        $candidates = ($contracts ?? $this->loadContracts($companyId, $employeeId))
            ->filter(fn (EmployeeContract $contract): bool => $this->coversDate($contract, $date)
                && $contract->payroll_category === PayrollCategory::Crew
                && $contract->resolvedSalaryStructure() === ContractSalaryStructure::Daily)
            ->values();

        if ($candidates->isEmpty()) {
            return [
                'contract' => null,
                'issue' => [
                    'code' => 'missing_historical_contract',
                    'message' => "No Daily Crew contract covers work date {$date}.",
                ],
            ];
        }

        $contract = $this->pickSingleCandidate($candidates);

        if ($contract === null) {
            return [
                'contract' => null,
                'issue' => [
                    'code' => 'overlapping_historical_contracts',
                    'message' => "Multiple Daily Crew contracts cover work date {$date}.",
                ],
            ];
        }

        return [
            'contract' => $contract,
            'issue' => null,
        ];
    }

    /**
     * @param  Collection<int, EmployeeContract>  $candidates
     */
    private function pickSingleCandidate(Collection $candidates): ?EmployeeContract
    {
        $active = $candidates->filter(
            fn (EmployeeContract $contract): bool => $contract->status === 'active',
        )->values();

        if ($active->count() === 1) {
            return $active->first();
        }

        if ($active->count() > 1) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $candidates
            ->sortByDesc(fn (EmployeeContract $contract): array => [
                $contract->start_date?->toDateString() ?? '',
                (int) $contract->id,
            ])
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function resolveOrFail(
        int $companyId,
        int $employeeId,
        CarbonInterface|string $workDate,
        ?Collection $contracts = null,
    ): EmployeeContract {
        $resolved = $this->resolve($companyId, $employeeId, $workDate, $contracts);

        if ($resolved['contract'] === null) {
            throw ValidationException::withMessages([
                'employee_id' => $resolved['issue']['message'] ?? 'Unable to resolve crew contract for work date.',
            ]);
        }

        return $resolved['contract'];
    }

    /**
     * @return Collection<int, EmployeeContract>
     */
    public function loadContracts(int $companyId, int $employeeId): Collection
    {
        return EmployeeContract::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('payroll_category', PayrollCategory::Crew)
            ->with([
                'salaryComponents',
                'salaryRevisions' => fn ($query) => $query->withoutTrashed()->with('lines'),
            ])
            ->get();
    }

    /**
     * Latest non-deleted revision with effective_from <= work date.
     *
     * Baseline components may be used only when the contract has never had a
     * salary revision. Deleted revision history must not reactivate potentially
     * mirrored current contract components as a historical fallback.
     *
     * @return array{revision: ?ContractSalaryRevision, issue: ?array{code: string, message: string}}
     */
    public function resolveSalaryRevision(EmployeeContract $contract, CarbonInterface|string $workDate): array
    {
        $date = CarbonImmutable::parse($workDate)->toDateString();

        $revisions = $contract->relationLoaded('salaryRevisions')
            ? $contract->salaryRevisions->reject(fn (ContractSalaryRevision $item): bool => $item->trashed())
            : $contract->salaryRevisions()->withoutTrashed()->with('lines')->get();

        if ($revisions->isEmpty()) {
            $hasEverHadRevisions = $contract->salaryRevisions()
                ->withTrashed()
                ->exists();

            if ($hasEverHadRevisions) {
                return [
                    'revision' => null,
                    'issue' => [
                        'code' => 'missing_historical_salary_revision',
                        'message' => "No valid salary revision covers work date {$date} for contract #{$contract->id}.",
                    ],
                ];
            }

            return ['revision' => null, 'issue' => null];
        }

        $revision = $revisions
            ->filter(fn (ContractSalaryRevision $item) => $item->effective_from !== null
                && $item->effective_from->toDateString() <= $date)
            ->sortBy([
                ['effective_from', 'desc'],
                ['version', 'desc'],
            ])
            ->first();

        if ($revision === null) {
            return [
                'revision' => null,
                'issue' => [
                    'code' => 'missing_historical_salary_revision',
                    'message' => "No salary revision covers work date {$date} for contract #{$contract->id}.",
                ],
            ];
        }

        return ['revision' => $revision, 'issue' => null];
    }

    private function coversDate(EmployeeContract $contract, string $date): bool
    {
        $start = $contract->start_date?->toDateString();
        $end = $contract->end_date?->toDateString();

        if ($start !== null && $start > $date) {
            return false;
        }

        if ($end !== null && $end < $date) {
            return false;
        }

        return true;
    }
}
