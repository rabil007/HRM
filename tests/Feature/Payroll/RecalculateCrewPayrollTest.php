<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;

test('recalculate applies crew monthly salary inputs using office deduction flow', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-MONTHLY',
        'status' => 'active',
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 50,
        'unpaid_leave_deduction' => 100,
        'total_deductions' => 150,
        'gross_salary' => 1000,
        'net_salary' => 850,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 1000,
                'net' => 850,
                'bonus' => 0,
                'other_deductions' => 50,
                'unpaid_leave_deduction' => 100,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 25,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $record->refresh();

    expect($record->other_deductions)->toBe('50.00')
        ->and($record->loan_deduction)->toBe('25.00')
        ->and($record->total_deductions)->toBe('175.00')
        ->and($record->net_salary)->toBe('825.00');
});

test('recalculate applies crew salary inputs to gross and net pay', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-01',
        'status' => 'active',
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 0,
        'total_deductions' => 0,
        'gross_salary' => 1000,
        'net_salary' => 1000,
        'calculation_breakdown' => [
            'base' => [
                'gross' => 1000,
                'net' => 1000,
                'bonus' => 0,
                'other_deductions' => 0,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 200,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 50,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $record->refresh();

    expect($record->bonus)->toBe('200.00')
        ->and($record->gross_salary)->toBe('1200.00')
        ->and($record->other_deductions)->toBe('50.00')
        ->and($record->total_deductions)->toBe('50.00')
        ->and($record->net_salary)->toBe('1150.00');
});
