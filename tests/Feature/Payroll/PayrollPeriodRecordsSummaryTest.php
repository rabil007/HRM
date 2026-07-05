<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\SalaryInput;
use Inertia\Testing\AssertableInertia as Assert;

test('pay run page includes payroll records summary with aggregated totals', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $firstEmployee = createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);
    $secondEmployee = createOfficeEmployeeWithContract($company, 'OFF-200', 8000, 0, 0, 0);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($firstEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 14500.00,
        'net_salary' => 14000.00,
        'total_deductions' => 500.00,
        'overtime_pay' => 0.00,
        'overtime_hours' => 0,
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($secondEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10300.00,
        'net_salary' => 10300.00,
        'total_deductions' => 0.00,
        'overtime_pay' => 0.00,
        'overtime_hours' => 0,
    ]);

    SalaryInput::factory()->for($company)->create([
        'employee_id' => $firstEmployee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 1000.00,
    ]);
    SalaryInput::factory()->for($company)->create([
        'employee_id' => $secondEmployee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => salaryInputTypeId($company, 'bonus'),
        'amount' => 300.00,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payroll_records_summary.employee_count', 2)
            ->where('payroll_records_summary.total_gross', '24800.00')
            ->where('payroll_records_summary.total_net', '24300.00')
            ->where('payroll_records_summary.total_additions', '1300.00')
            ->where('payroll_records_summary.total_deductions', '500.00')
            ->where('payroll_records_summary.total_overtime_pay', '0.00')
            ->where('payroll_records_summary.total_overtime_hours', '0.00'));
});

test('pay run page includes crew overtime totals in payroll records summary', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $firstEmployee = createCrewEmployeeWithContract($company, 'CREW-100', 500, 200, 300, 10000);
    $secondEmployee = createCrewEmployeeWithContract($company, 'CREW-200', 500, 200, 300, 8000);

    PayrollRecord::factory()->for($company)->for($period, 'period')->for($firstEmployee)->create([
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 5000.00,
        'net_salary' => 5000.00,
        'overtime_pay' => 2092.60,
        'overtime_hours' => 76,
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($secondEmployee)->create([
        'payroll_category' => PayrollCategory::Crew,
        'gross_salary' => 3000.00,
        'net_salary' => 3000.00,
        'overtime_pay' => 500.00,
        'overtime_hours' => 20,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payroll_records_summary.total_overtime_pay', '2592.60')
            ->where('payroll_records_summary.total_overtime_hours', '96.00'));
});

test('pay run page omits payroll records summary when no records exist', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->office()->create([
        'status' => PayrollPeriodStatus::Draft,
    ]);

    createOfficeEmployeeWithContract($company, 'OFF-100', 10000, 0, 0, 0);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'employees']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payroll_records_summary', null));
});
