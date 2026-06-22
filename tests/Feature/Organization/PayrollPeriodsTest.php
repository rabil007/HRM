<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access payroll hub', function () {
    $this->get(route('organization.payroll.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll.index'))
        ->assertForbidden();
});

test('authorized users can list and create payroll periods from payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.create',
        'payroll.crew_timesheets.view',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/payroll/index')
            ->has('periods', 0)
            ->where('permissions.create_period', true)
            ->where('permissions.view_crew_timesheets', true));

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.payroll.periods.store'), [
            'name' => 'June 2026',
            'payroll_category' => 'crew',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
            'notes' => 'Monthly payroll',
        ])
        ->assertRedirect(route('organization.payroll.index'));

    $this->assertDatabaseHas('payroll_periods', [
        'company_id' => $company->id,
        'name' => 'June 2026',
        'payroll_category' => 'crew',
        'status' => PayrollPeriodStatus::Draft->value,
    ]);
});

test('same start date can be used for crew and office pay periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.payroll.periods.store'), [
            'name' => 'June 2026 Crew',
            'payroll_category' => 'crew',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
        ])
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.payroll.periods.store'), [
            'name' => 'June 2026 Office',
            'payroll_category' => 'office',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
        ])
        ->assertRedirect();

    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('legacy payroll period routes redirect to payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll-periods.index'))
        ->assertRedirect(route('organization.payroll.index'));
});
