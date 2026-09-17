<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\RecalculateCrewPayroll;

test('recalculate applies crew monthly salary inputs without re-deducting informational unpaid leave', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-MONTHLY',
        'status' => 'active',
    ]);

    // Monthly crew gross salary is already prorated for unpaid leave during generation.
    // Base unpaid leave deduction is purely informational and must not enter total deductions.
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 50,
        'unpaid_leave_deduction' => 100,
        'total_deductions' => 50,
        'gross_salary' => 1000,
        'net_salary' => 950,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 1000,
                'net' => 950,
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
        ->and($record->unpaid_leave_deduction)->toBe('100.00')
        ->and($record->total_deductions)->toBe('75.00')
        ->and($record->net_salary)->toBe('925.00')
        ->and($record->calculation_breakdown['informational_unpaid_leave_deduction'])->toEqual(100.0)
        ->and($record->calculation_breakdown['manual_unpaid_leave_deduction'])->toEqual(0.0);
});

test('recalculate monthly crew is idempotent when run repeatedly', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-IDEMPOTENT',
        'status' => 'active',
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 30,
        'unpaid_leave_deduction' => 80,
        'total_deductions' => 30,
        'gross_salary' => 1200,
        'net_salary' => 1170,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 1200,
                'net' => 1170,
                'bonus' => 0,
                'other_deductions' => 30,
                'unpaid_leave_deduction' => 80,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 40,
    ]);

    $recalculator = app(RecalculateCrewPayroll::class);
    $recalculator->handle($period);

    $record->refresh();
    expect($record->total_deductions)->toBe('70.00')
        ->and($record->net_salary)->toBe('1130.00');

    // Run recalculation a second time; figures must not double or drift
    $recalculator->handle($period);

    $record->refresh();
    expect($record->other_deductions)->toBe('30.00')
        ->and($record->loan_deduction)->toBe('40.00')
        ->and($record->unpaid_leave_deduction)->toBe('80.00')
        ->and($record->total_deductions)->toBe('70.00')
        ->and($record->net_salary)->toBe('1130.00');
});

test('recalculate handles monthly crew with zero unpaid leave days', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-ZERO-UNPAID',
        'status' => 'active',
    ]);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 0,
        'unpaid_leave_deduction' => 0,
        'total_deductions' => 0,
        'gross_salary' => 2000,
        'net_salary' => 2000,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 2000,
                'net' => 2000,
                'bonus' => 0,
                'other_deductions' => 0,
                'unpaid_leave_deduction' => 0,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 100,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $record->refresh();

    expect($record->bonus)->toBe('300.00')
        ->and($record->loan_deduction)->toBe('100.00')
        ->and($record->gross_salary)->toBe('2300.00')
        ->and($record->total_deductions)->toBe('100.00')
        ->and($record->net_salary)->toBe('2200.00');
});

test('recalculate handles monthly crew explicit unpaid leave input as real deduction', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-MANUAL-UNPAID',
        'status' => 'active',
    ]);

    // Base unpaid leave is 100 (informational), but payroll officer adds a manual unpaid-leave adjustment of 40
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 0,
        'unpaid_leave_deduction' => 100,
        'total_deductions' => 0,
        'gross_salary' => 1000,
        'net_salary' => 1000,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 1000,
                'net' => 1000,
                'bonus' => 0,
                'other_deductions' => 0,
                'unpaid_leave_deduction' => 100,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'unpaid_leave'),
        'amount' => 40,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $record->refresh();

    // Informational 100 + manual 40 = 140 unpaid_leave_deduction
    // Only manual 40 enters total_deductions
    expect($record->unpaid_leave_deduction)->toBe('140.00')
        ->and($record->total_deductions)->toBe('40.00')
        ->and($record->net_salary)->toBe('960.00')
        ->and($record->calculation_breakdown['informational_unpaid_leave_deduction'])->toEqual(100.0)
        ->and($record->calculation_breakdown['manual_unpaid_leave_deduction'])->toEqual(40.0);
});

test('recalculate handles monthly crew full period unpaid leave', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-RC-FULL-UNPAID',
        'status' => 'active',
    ]);

    // Full unpaid leave: earned salary was 0, bonus input 50, loan 20
    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Crew,
        'bonus' => 0,
        'other_deductions' => 0,
        'unpaid_leave_deduction' => 1000,
        'total_deductions' => 0,
        'gross_salary' => 0,
        'net_salary' => 0,
        'calculation_breakdown' => [
            'salary_structure' => 'monthly',
            'base' => [
                'gross' => 0,
                'net' => 0,
                'bonus' => 0,
                'other_deductions' => 0,
                'unpaid_leave_deduction' => 1000,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 50,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 20,
    ]);

    app(RecalculateCrewPayroll::class)->handle($period);

    $record->refresh();

    expect($record->gross_salary)->toBe('50.00')
        ->and($record->loan_deduction)->toBe('20.00')
        ->and($record->unpaid_leave_deduction)->toBe('1000.00')
        ->and($record->total_deductions)->toBe('20.00')
        ->and($record->net_salary)->toBe('30.00');
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
