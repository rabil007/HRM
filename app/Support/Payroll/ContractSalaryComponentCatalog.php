<?php

namespace App\Support\Payroll;

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;

final class ContractSalaryComponentCatalog
{
    /**
     * @return list<array{
     *     component_code: SalaryComponentCode,
     *     component_name: string,
     *     rate_type: string,
     *     status: SalaryComponentStatus
     * }>
     */
    public static function definitionsFor(
        PayrollCategory $category,
        ?ContractSalaryStructure $structure = null,
    ): array {
        return array_map(
            fn (SalaryComponentCode $code): array => [
                'component_code' => $code,
                'component_name' => $code->label(),
                'rate_type' => $code->defaultRateTypeFor($category, $structure)->value,
                'status' => SalaryComponentStatus::Active,
            ],
            self::componentCodesFor($category, $structure),
        );
    }

    /**
     * Maps legacy employee_contracts columns to salary component codes per payroll category.
     *
     * @return array<string, SalaryComponentCode>
     */
    public static function legacyColumnMap(
        PayrollCategory $category,
        ?ContractSalaryStructure $structure = null,
    ): array {
        if ($category === PayrollCategory::Office) {
            return [
                'basic_salary' => SalaryComponentCode::Basic,
                'housing_allowance' => SalaryComponentCode::Housing,
                'transport_allowance' => SalaryComponentCode::Transport,
                'other_allowances' => SalaryComponentCode::Other,
            ];
        }

        $structure ??= ContractSalaryStructure::Daily;

        if ($structure === ContractSalaryStructure::Monthly) {
            return [
                'basic_salary' => SalaryComponentCode::Basic,
                'housing_allowance' => SalaryComponentCode::Housing,
                'transport_allowance' => SalaryComponentCode::Transport,
                'other_allowances' => SalaryComponentCode::Other,
            ];
        }

        return [
            'basic_salary' => SalaryComponentCode::Basic,
            'supplementary_allowance' => SalaryComponentCode::SupplementaryAllowance,
            'site_allowance' => SalaryComponentCode::SiteAllowance,
        ];
    }

    /**
     * @return list<SalaryComponentCode>
     */
    public static function componentCodesFor(
        PayrollCategory $category,
        ?ContractSalaryStructure $structure = null,
    ): array {
        if ($category === PayrollCategory::Office) {
            return SalaryComponentCode::forPayrollCategory($category);
        }

        $structure ??= ContractSalaryStructure::Daily;

        return match ($structure) {
            ContractSalaryStructure::Monthly => [
                SalaryComponentCode::Basic,
                SalaryComponentCode::Housing,
                SalaryComponentCode::Transport,
                SalaryComponentCode::Other,
            ],
            ContractSalaryStructure::Daily => [
                SalaryComponentCode::Basic,
                SalaryComponentCode::SiteAllowance,
                SalaryComponentCode::SupplementaryAllowance,
            ],
        };
    }
}
