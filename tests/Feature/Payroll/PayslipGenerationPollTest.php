<?php

use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Inertia\Testing\AssertableInertia as Assert;

test('payroll show payslip poll partial reload returns summary and record payslip flags', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create([
        'status' => PayrollPeriodStatus::Approved,
        'payroll_category' => PayrollCategory::Office,
    ]);

    $generatedEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $pendingEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $generatedRecord = PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $generatedEmployee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
        'payslip_path' => 'payslips/1/1/generated.pdf',
    ]);

    $pendingRecord = PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $pendingEmployee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
        'payslip_path' => null,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->reloadOnly(
                'payslip_summary,payroll_records,payroll_records_pagination',
                fn (Assert $partial) => $partial
                    ->where('payslip_summary.total', 2)
                    ->where('payslip_summary.generated', 1)
                    ->where('payslip_summary.pending', 1)
                    ->has('payroll_records', 2)
                    ->where('payroll_records', function ($records) use ($generatedRecord, $pendingRecord): bool {
                        $byId = collect($records)->keyBy('id');

                        return $byId[$generatedRecord->id]['has_payslip'] === true
                            && $byId[$pendingRecord->id]['has_payslip'] === false;
                    })
                    ->missing('rows')
                    ->missing('department_tree'),
            ));
});

test('payroll show full reload still returns complete page when partial data includes unrelated props', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
    ]);

    $period = PayrollPeriod::factory()->for($company)->approved()->create([
        'payroll_category' => PayrollCategory::Office,
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    PayrollRecord::factory()->for($company)->create([
        'period_id' => $period->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'approved',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.show', ['payrollPeriod' => $period, 'tab' => 'payroll']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/show')
            ->reloadOnly(
                'payslip_summary,rows',
                fn (Assert $partial) => $partial
                    ->has('rows')
                    ->where('payslip_summary.total', 1),
            ));
});
