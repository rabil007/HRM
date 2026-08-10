<?php

namespace App\Support\Payroll;

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\EmployeeContract;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ResolveContractRatesForPeriod
{
    public function __construct(
        private readonly ResolveEffectiveContractSalaryComponents $resolveEffectiveComponents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(EmployeeContract $contract, CarbonInterface $asOf): array
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();
        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);
        $resolved = $this->resolveEffectiveComponents->resolve($contract, $asOf);

        $rates = [];

        foreach ($columnMap as $column => $code) {
            $amount = $this->amountFor($resolved['components'], $code);
            $rates[$column] = $amount ?? ($resolved['has_revision_history'] ? null : $contract->{$column});
        }

        $rates['salary_revision_id'] = $resolved['revision']?->id;
        $rates['salary_resolution_issue'] = $resolved['issue'];

        return $rates;
    }

    /**
     * @param  Collection<int, ContractSalaryComponent>  $components
     */
    private function amountFor($components, SalaryComponentCode $code): mixed
    {
        $component = $components->first(
            fn (ContractSalaryComponent $item) => $item->component_code === $code
                && ($item->status === null || $item->status === SalaryComponentStatus::Active),
        );

        return $component?->amount;
    }
}
