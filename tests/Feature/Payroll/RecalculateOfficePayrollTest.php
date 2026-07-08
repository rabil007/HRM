<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use App\Support\Payroll\Actions\RecalculateOfficePayroll;
use App\Support\Payroll\PayslipData;

test('recalculate applies multiple salary inputs to gross and net with typed column mapping', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-RC-01', 10000, 2000, 1000, 500);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 10000,
        'housing_allowance' => 2000,
        'transport_allowance' => 1000,
        'other_allowances' => 500,
        'gross_salary' => 13500,
        'net_salary' => 13500,
        'calculation_breakdown' => [
            'base' => [
                'basic' => 10000,
                'housing' => 2000,
                'transport' => 1000,
                'other' => 500,
                'gross' => 13500,
                'net' => 13500,
                'bonus' => 0,
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
        'salary_input_type_id' => salaryInputTypeId($company, 'commission'),
        'amount' => 200,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'unpaid_leave'),
        'amount' => 400,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'loan'),
        'amount' => 100,
    ]);

    app(RecalculateOfficePayroll::class)->handle($period);

    $record->refresh();

    expect($record->bonus)->toBe('500.00')
        ->and($record->gross_salary)->toBe('14000.00')
        ->and($record->unpaid_leave_deduction)->toBe('400.00')
        ->and($record->loan_deduction)->toBe('100.00')
        ->and($record->total_deductions)->toBe('500.00')
        ->and($record->net_salary)->toBe('13500.00');

    $payslip = PayslipData::for($record, $company->id);

    expect(collect($payslip['earnings'])->firstWhere('label', 'Bonus')['amount'] ?? null)->toBe('300.00')
        ->and(collect($payslip['earnings'])->firstWhere('label', 'Commission')['amount'] ?? null)->toBe('200.00')
        ->and($payslip['deductions'])->toHaveCount(2)
        ->and($payslip['deductions'][0]['label'])->toBe('Unpaid leave')
        ->and($payslip['deductions'][0]['amount'])->toBe('400.00')
        ->and($payslip['deductions'][1]['label'])->toBe('Loan')
        ->and($payslip['deductions'][1]['amount'])->toBe('100.00');
});

test('office payslip shows assigned salary inputs before payroll recalculation snapshot', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-PS-01', 2200, 0, 0, 0);

    $record = PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 2200,
        'gross_salary' => 2200,
        'net_salary' => 2200,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 200,
    ]);

    $payslip = PayslipData::for($record, $company->id);

    expect(collect($payslip['earnings'])->firstWhere('label', 'Bonus')['amount'] ?? null)->toBe('200.00');
});

test('authorized users can recalculate office payroll from the period screen', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.recalculate']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);
    $employee = createOfficeEmployeeWithContract($company, 'OFF-RC-02', 10000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10000,
        'net_salary' => 10000,
        'calculation_breakdown' => [
            'base' => [
                'basic' => 10000,
                'housing' => 0,
                'transport' => 0,
                'other' => 0,
                'gross' => 10000,
                'net' => 10000,
                'bonus' => 0,
            ],
        ],
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'other'),
        'amount' => 250,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.recalculate', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]))
        ->assertSessionHas('success');

    $record = PayrollRecord::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($record?->other_deductions)->toBe('250.00')
        ->and($record?->net_salary)->toBe('9750.00');
});
