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
        $components = $this->resolveEffectiveComponents->handle($contract, $asOf);

        $rates = [];

        foreach ($columnMap as $column => $code) {
            $rates[$column] = $this->amountFor($components, $code) ?? $contract->{$column};
        }

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
