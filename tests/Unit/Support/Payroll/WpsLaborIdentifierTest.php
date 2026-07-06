<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Support\Payroll\Wps\WpsLaborIdentifier;

test('wps labor identifier prefers active contract labor_contract_id', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create();

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

test('wps labor identifier returns null when contract labor_contract_id is missing', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create();

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

    expect(WpsLaborIdentifier::forPayrollRecord($record))->toBeNull();
});

test('wps labor identifier prefers stored contract snapshot over current active contract', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = Employee::factory()->forCompany($company)->create();

    $snapshotContract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'ended',
        'labor_contract_id' => 'SNAPSHOT-111',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
        'labor_contract_id' => 'ACTIVE-999',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'contract_id' => $snapshotContract->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    expect(WpsLaborIdentifier::forPayrollRecord($record))->toBe('SNAPSHOT-111');
});
