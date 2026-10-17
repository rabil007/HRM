<?php

use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Departments\DepartmentManagerExportContext;
use App\Support\Employees\EmployeeExportFieldRegistry;
use App\Support\Employees\EmployeeExportFieldResolver;

test('employee export includes payable EOS only for monthly crew', function (string $category, string $structure, float $expected) {
    $contract = new EmployeeContract([
        'payroll_category' => $category, 'salary_structure' => $structure,
        'basic_salary' => 15000, 'housing_allowance' => 10000,
        'transport_allowance' => 5000, 'other_allowances' => 24125, 'eos_monthly_amount' => 875,
    ]);
    $employee = (new Employee)->setRelation('currentContract', $contract);
    $result = (new EmployeeExportFieldResolver(DepartmentManagerExportContext::forCompany(0)))->resolve($employee, [
        'contract_total_compensation_aed', 'contract_total_compensation_usd',
    ]);
    expect($result['contract_total_compensation_aed'])->toBe($expected)
        ->and($result['contract_total_compensation_usd'])->toBe(round($expected / EmployeeExportFieldRegistry::AED_PER_USD, 2));
})->with([
    'monthly crew' => ['crew', 'monthly', 55000.0],
    'office remains unchanged' => ['office', 'monthly', 54125.0],
    'daily crew legacy export remains unchanged' => ['crew', 'daily', 54125.0],
]);
