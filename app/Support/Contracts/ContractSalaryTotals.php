<?php

namespace App\Support\Contracts;

use App\Models\EmployeeContract;
use App\Support\Employees\EmployeeExportFieldRegistry;

final class ContractSalaryTotals
{
    public static function total(EmployeeContract $contract, string $payrollCategory = ''): float
    {
        $category = $payrollCategory !== ''
            ? $payrollCategory
            : ($contract->payroll_category?->value ?? 'crew');

        return match ($category) {
            'crew' => $contract->resolvedSalaryStructure()->value === 'monthly'
                ? self::officeTotal($contract)
                : self::crewTotal($contract),
            'office' => self::officeTotal($contract),
            default => 0.0,
        };
    }

    public static function totalUsd(EmployeeContract $contract, string $payrollCategory = ''): float
    {
        return round(
            self::total($contract, $payrollCategory) / EmployeeExportFieldRegistry::AED_PER_USD,
            2,
        );
    }

    public static function crewTotal(EmployeeContract $contract): float
    {
        return (float) $contract->basic_salary
            + (float) $contract->supplementary_allowance
            + (float) $contract->site_allowance;
    }

    public static function officeTotal(EmployeeContract $contract): float
    {
        return (float) $contract->basic_salary
            + (float) $contract->housing_allowance
            + (float) $contract->transport_allowance
            + (float) $contract->other_allowances;
    }
}
