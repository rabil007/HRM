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

        $revision = $revisionHistory
            ->reject(fn (ContractSalaryRevision $item): bool => $item->trashed())
            ->filter(fn (ContractSalaryRevision $item): bool => $item->effective_from !== null
                && $item->effective_from->toDateString() <= $asOfDate)
            ->sortBy([
                ['effective_from', 'desc'],
                ['version', 'desc'],
            ])
            ->first();

        if ($revision === null && $revisionHistory->isNotEmpty()) {
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
