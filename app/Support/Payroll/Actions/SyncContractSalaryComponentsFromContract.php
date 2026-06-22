<?php

namespace App\Support\Payroll\Actions;

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\EmployeeContract;
use App\Support\Payroll\ContractSalaryComponentCatalog;

final class SyncContractSalaryComponentsFromContract
{
    /**
     * @var list<SalaryComponentCode>
     */
    private const OBSOLETE_CREW_COMPONENTS = [
        SalaryComponentCode::StandbyRate,
        SalaryComponentCode::OnsiteRate,
        SalaryComponentCode::OtRate,
    ];

    public function handle(EmployeeContract $contract): void
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;

        if ($category === PayrollCategory::Crew) {
            $this->deactivateObsoleteCrewComponents($contract);
        }

        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category);

        foreach ($columnMap as $column => $componentCode) {
            $this->syncComponent(
                $contract,
                $category,
                $componentCode,
                $contract->{$column},
            );
        }
    }

    private function deactivateObsoleteCrewComponents(EmployeeContract $contract): void
    {
        ContractSalaryComponent::query()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->whereIn('component_code', array_map(
                fn (SalaryComponentCode $code): string => $code->value,
                self::OBSOLETE_CREW_COMPONENTS,
            ))
            ->update([
                'status' => SalaryComponentStatus::Inactive->value,
                'updated_at' => now(),
            ]);
    }

    private function syncComponent(
        EmployeeContract $contract,
        PayrollCategory $category,
        SalaryComponentCode $componentCode,
        mixed $amount,
    ): void {
        if ($amount === null || $amount === '' || (float) $amount <= 0) {
            ContractSalaryComponent::query()
                ->where('company_id', $contract->company_id)
                ->where('contract_id', $contract->id)
                ->where('component_code', $componentCode->value)
                ->update([
                    'status' => SalaryComponentStatus::Inactive->value,
                    'updated_at' => now(),
                ]);

            return;
        }

        ContractSalaryComponent::query()->updateOrCreate(
            [
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'component_code' => $componentCode->value,
            ],
            [
                'component_name' => $componentCode->label(),
                'rate_type' => $componentCode->defaultRateTypeFor($category)->value,
                'amount' => $amount,
                'status' => SalaryComponentStatus::Active->value,
            ],
        );
    }
}
