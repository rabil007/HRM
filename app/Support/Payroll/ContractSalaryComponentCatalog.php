<?php

namespace App\Support\Payroll;

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
    public static function definitionsFor(PayrollCategory $category): array
    {
        return array_map(
            fn (SalaryComponentCode $code): array => [
                'component_code' => $code,
                'component_name' => $code->label(),
                'rate_type' => $code->defaultRateTypeFor($category)->value,
                'status' => SalaryComponentStatus::Active,
            ],
            SalaryComponentCode::forPayrollCategory($category),
        );
    }

    /**
     * Maps legacy employee_contracts columns to salary component codes per payroll category.
     *
     * @return array<string, SalaryComponentCode>
     */
    public static function legacyColumnMap(PayrollCategory $category): array
    {
        return match ($category) {
            PayrollCategory::Office => [
                'basic_salary' => SalaryComponentCode::Basic,
                'housing_allowance' => SalaryComponentCode::Housing,
                'transport_allowance' => SalaryComponentCode::Transport,
                'other_allowances' => SalaryComponentCode::Other,
            ],
            PayrollCategory::Crew => [
                'basic_salary' => SalaryComponentCode::Basic,
                'supplementary_allowance' => SalaryComponentCode::SupplementaryAllowance,
                'site_allowance' => SalaryComponentCode::SiteAllowance,
            ],
        };
    }
}
