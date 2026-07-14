<?php

namespace App\Support\Payroll;

use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ResolveEffectiveContractSalaryComponents
{
    /**
     * @return Collection<int, ContractSalaryComponent>
     */
    public function handle(EmployeeContract $contract, CarbonInterface $asOf): Collection
    {
        $asOfDate = $asOf->toDateString();

        $revision = $contract->relationLoaded('salaryRevisions')
            ? $contract->salaryRevisions
                ->filter(fn (ContractSalaryRevision $item) => $item->effective_from !== null
                    && $item->effective_from->toDateString() <= $asOfDate)
                ->sortBy([
                    ['effective_from', 'desc'],
                    ['version', 'desc'],
                ])
                ->first()
            : ContractSalaryRevision::query()
                ->where('contract_id', $contract->id)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->with('lines')
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

        if ($revision === null) {
            return $contract->relationLoaded('salaryComponents')
                ? $contract->salaryComponents
                : $contract->salaryComponents()->get();
        }

        if (! $revision->relationLoaded('lines')) {
            $revision->load('lines');
        }

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
