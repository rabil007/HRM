<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ResolveEffectiveContractSalaryComponents
{
    /**
     * Resolve the payroll-applicable salary package for a contract as of a date.
     *
     * Revision history remains visible for contract management even when a
     * revision is not applied to payroll. Payroll rates use only revisions with
     * apply_to_payroll=true, ordered by payroll_effective_from then version.
     *
     * @return array{
     *     components: Collection<int, ContractSalaryComponent>,
     *     revision: ContractSalaryRevision|null,
     *     has_revision_history: bool,
     *     issue: array{code: string, message: string}|null
     * }
     */
    public function resolve(EmployeeContract $contract, CarbonInterface $asOf): array
    {
        $asOfDate = $asOf->toDateString();
        $revisionHistory = $contract->relationLoaded('salaryRevisionHistory')
            ? $contract->salaryRevisionHistory
            : $contract->salaryRevisionHistory()->with('lines')->get();

        $hasRevisionHistory = $revisionHistory->isNotEmpty();

        $revision = $revisionHistory
            ->reject(fn (ContractSalaryRevision $item): bool => $item->trashed())
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

        if ($revision === null && $hasRevisionHistory) {
            return [
                'components' => collect(),
                'revision' => null,
                'has_revision_history' => true,
                'issue' => [
                    'code' => 'missing_historical_salary_revision',
                    'message' => "No salary revision covers {$asOfDate} for contract #{$contract->id}.",
                ],
            ];
        }

        if ($revision === null) {
            return [
                'components' => $contract->relationLoaded('salaryComponents')
                    ? $contract->salaryComponents
                    : $contract->salaryComponents()->get(),
                'revision' => null,
                'has_revision_history' => false,
                'issue' => null,
            ];
        }

        if (! $revision->relationLoaded('lines')) {
            $revision->load('lines');
        }

        return [
            'components' => $this->componentsFromRevision($contract, $revision),
            'revision' => $revision,
            'has_revision_history' => true,
            'issue' => null,
        ];
    }

    /**
     * @return Collection<int, ContractSalaryComponent>
     */
    public function handle(EmployeeContract $contract, CarbonInterface $asOf): Collection
    {
        $resolved = $this->resolve($contract, $asOf);

        if ($resolved['issue'] !== null) {
            throw ValidationException::withMessages([
                'salary_revision' => $resolved['issue']['message'],
            ]);
        }

        return $resolved['components'];
    }

    /**
     * @return Collection<int, ContractSalaryComponent>
     */
    private function componentsFromRevision(
        EmployeeContract $contract,
        ContractSalaryRevision $revision,
    ): Collection {
        return $revision->lines
            ->map(fn (ContractSalaryRevisionLine $line): ContractSalaryComponent => new ContractSalaryComponent([
                'company_id' => $line->company_id,
                'contract_id' => $contract->id,
                'component_code' => $line->component_code,
                'component_name' => $line->component_name,
                'rate_type' => $line->rate_type,
                'amount' => $line->amount,
                'status' => SalaryComponentStatus::Active,
            ]))
            ->values();
    }
}
