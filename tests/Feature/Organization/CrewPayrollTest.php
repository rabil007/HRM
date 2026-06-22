<?php

use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access crew payroll board', function () {
    $this->get(route('organization.crew-payroll.index'))
        ->assertRedirect(route('login'));
});

test('crew payroll board lists only employees with active crew contracts', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $period = PayrollPeriod::factory()->for($company)->create([
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'payment_date' => '2026-06-05',
    ]);

    $crewEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'CREW-001',
        'name' => 'Crew Member',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $officeEmployee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OFF-001',
        'name' => 'Office Member',
        'status' => 'active',
    ]);

    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-payroll.index', ['period_id' => $period->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-payroll/index')
            ->has('rows', 1)
            ->where('rows.0.employee.id', $crewEmployee->id)
            ->where('rows.0.is_filled', false));
});

test('authorized users can upsert crew timesheets for draft periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $payload = [
        'period_id' => $period->id,
        'employee_id' => $crewEmployee->id,
        'standby_days' => 10,
        'onsite_days' => 15,
        'overtime_amount' => 250.50,
        'additional_amount' => 100,
        'deduction_amount' => 50,
        'remarks' => 'May payroll',
    ];

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-payroll.timesheets.store'), $payload)
        ->assertRedirect(route('organization.crew-payroll.index', ['period_id' => $period->id]));

    $this->assertDatabaseHas('crew_timesheets', [
        'company_id' => $company->id,
        'employee_id' => $crewEmployee->id,
        'period_id' => $period->id,
        'standby_days' => 10,
        'onsite_days' => 15,
        'overtime_amount' => 250.50,
        'remarks' => 'May payroll',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-payroll.timesheets.store'), [
            ...$payload,
            'standby_days' => 12,
            'remarks' => 'Updated',
        ])
        ->assertRedirect();

    expect(CrewTimesheet::query()->where('period_id', $period->id)->count())->toBe(1);

    $this->assertDatabaseHas('crew_timesheets', [
        'employee_id' => $crewEmployee->id,
        'period_id' => $period->id,
        'standby_days' => 12,
        'remarks' => 'Updated',
    ]);
});

test('crew timesheets cannot be saved for non-draft periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->approved()->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-payroll.timesheets.store'), [
            'period_id' => $period->id,
            'employee_id' => $crewEmployee->id,
            'standby_days' => 5,
        ])
        ->assertSessionHasErrors('period_id');
});

test('crew timesheets cannot be saved for office employees', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $officeEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $officeEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-payroll.timesheets.store'), [
            'period_id' => $period->id,
            'employee_id' => $officeEmployee->id,
            'standby_days' => 5,
        ])
        ->assertSessionHasErrors('employee_id');
});

test('crew timesheet validation rejects invalid date ranges', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.update',
    ]);

    $period = PayrollPeriod::factory()->for($company)->create();

    $crewEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeContract::factory()->create([
        'employee_id' => $crewEmployee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-payroll.timesheets.store'), [
            'period_id' => $period->id,
            'employee_id' => $crewEmployee->id,
            'standby_from' => '2026-06-10',
            'standby_to' => '2026-06-01',
        ])
        ->assertSessionHasErrors('standby_to');
});
