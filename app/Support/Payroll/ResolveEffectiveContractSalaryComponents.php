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
        $revision = ContractSalaryRevision::query()
            ->where('contract_id', $contract->id)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->with('lines')
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($revision === null) {
            return $contract->relationLoaded('salaryComponents')
                ? $contract->salaryComponents
                : $contract->salaryComponents()->get();
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
