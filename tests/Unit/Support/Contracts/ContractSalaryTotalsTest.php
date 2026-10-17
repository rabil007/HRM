<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use App\Support\Contracts\ContractSalaryTotals;

test('monthly crew contract total includes EOS with regular allowances', function () {
    $contract = new EmployeeContract([
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Monthly,
        'basic_salary' => 15000,
        'housing_allowance' => 10000,
        'transport_allowance' => 5000,
        'other_allowances' => 24125,
        'eos_monthly_amount' => 875,
        'supplementary_allowance' => 900,
        'site_allowance' => 1000,
    ]);

    expect(ContractSalaryTotals::total($contract, 'crew'))->toBe(55000.0);
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

test('office contract totals exclude monthly crew EOS', function () {
    $contract = new EmployeeContract([
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 15000, 'housing_allowance' => 10000,
        'transport_allowance' => 5000, 'other_allowances' => 24125, 'eos_monthly_amount' => 875,
    ]);
    expect(ContractSalaryTotals::total($contract))->toBe(54125.0);
});
