<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use App\Support\Contracts\ContractSalaryTotals;

test('monthly crew contract total includes regular allowances but excludes EOS provision', function () {
    $contract = new EmployeeContract([
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Monthly,
        'basic_salary' => 55000,
        'housing_allowance' => 2000,
        'transport_allowance' => 500,
        'other_allowances' => 0,
        'eos_monthly_amount' => 1500,
        'supplementary_allowance' => 900,
        'site_allowance' => 1000,
    ]);

    expect(ContractSalaryTotals::total($contract, 'crew'))->toBe(57500.0);
});

test('daily crew contract total is unchanged by monthly allowances or EOS', function () {
    $contract = new EmployeeContract([
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'basic_salary' => 200,
        'supplementary_allowance' => 50,
        'site_allowance' => 40,
        'housing_allowance' => 300,
        'eos_monthly_amount' => 200,
    ]);

    expect(ContractSalaryTotals::total($contract, 'crew'))->toBe(290.0);
});
