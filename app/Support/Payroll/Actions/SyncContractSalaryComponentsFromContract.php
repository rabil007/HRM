<?php

namespace App\Support\Payroll\Actions;

use App\Enums\ContractSalaryStructure;
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

    /**
     * @var list<SalaryComponentCode>
     */
    private const CREW_DAILY_COMPONENTS = [
        SalaryComponentCode::SiteAllowance,
        SalaryComponentCode::SupplementaryAllowance,
    ];

    /**
     * @var list<SalaryComponentCode>
     */
    private const CREW_MONTHLY_COMPONENTS = [
        SalaryComponentCode::Housing,
        SalaryComponentCode::Transport,
        SalaryComponentCode::Other,
    ];

    public function handle(EmployeeContract $contract): void
    {
        $category = $contract->payroll_category ?? PayrollCategory::Office;
        $structure = $contract->resolvedSalaryStructure();

        if ($category === PayrollCategory::Crew) {
            $this->deactivateObsoleteCrewComponents($contract);
            $this->deactivateIncompatibleCrewComponents($contract, $structure);
        }

        $columnMap = ContractSalaryComponentCatalog::legacyColumnMap($category, $structure);

        foreach ($columnMap as $column => $componentCode) {
            $this->syncComponent(
                $contract,
                $category,
                $structure,
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

    private function deactivateIncompatibleCrewComponents(
        EmployeeContract $contract,
        ContractSalaryStructure $structure,
    ): void {
        $inactiveCodes = $structure === ContractSalaryStructure::Monthly
            ? self::CREW_DAILY_COMPONENTS
            : self::CREW_MONTHLY_COMPONENTS;

        ContractSalaryComponent::query()
            ->where('company_id', $contract->company_id)
            ->where('contract_id', $contract->id)
            ->whereIn('component_code', array_map(
                fn (SalaryComponentCode $code): string => $code->value,
                $inactiveCodes,
            ))
            ->update([
                'status' => SalaryComponentStatus::Inactive->value,
                'updated_at' => now(),
            ]);
    }

    private function syncComponent(
        EmployeeContract $contract,
        PayrollCategory $category,
        ContractSalaryStructure $structure,
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
                'rate_type' => $componentCode->defaultRateTypeFor($category, $structure)->value,
                'amount' => $amount,
                'status' => SalaryComponentStatus::Active->value,
            ],
        );
    }
}
