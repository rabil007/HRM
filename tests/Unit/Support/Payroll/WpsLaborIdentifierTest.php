<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsLaborIdentifier;

test('wps labor identifier prefers active contract labor_contract_id', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create([
        'labor_card_number' => null,
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
        'labor_contract_id' => '2255',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'approved',
    ]);

    expect(WpsLaborIdentifier::forPayrollRecord($record))->toBe('2255');
});

test('wps labor identifier falls back to employee labor_card_number', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create([
        'labor_card_number' => '99887766554433',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'status' => 'active',
        'labor_contract_id' => null,
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    expect(WpsLaborIdentifier::forPayrollRecord($record))->toBe('99887766554433');
});
