<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
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
    ]);
    PayrollRecord::factory()->for($company)->for($period, 'period')->for($secondEmployee)->create([
        'payroll_category' => PayrollCategory::Office,
        'gross_salary' => 10300.00,
        'net_salary' => 10300.00,
        'total_deductions' => 0.00,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->where('payroll_records_summary.employee_count', 2)
            ->where('payroll_records_summary.total_gross', '24800.00')
            ->where('payroll_records_summary.total_net', '24300.00')
            ->where('payroll_records_summary.total_deductions', '500.00'));
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
