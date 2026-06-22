<?php

use App\Enums\PayrollPeriodStatus;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access payroll periods', function () {
    $this->get(route('organization.payroll-periods.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access payroll periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll-periods.index'))
        ->assertForbidden();
});

test('authorized users can list and create payroll periods', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'payroll.periods.view',
        'payroll.periods.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll-periods.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/payroll-periods/index')
            ->has('periods', 0)
            ->where('permissions.create', true));

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('organization.payroll-periods.store'), [
            'name' => 'June 2026',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
            'notes' => 'Monthly payroll',
        ])
        ->assertRedirect(route('organization.payroll-periods.index'));

    $this->assertDatabaseHas('payroll_periods', [
        'company_id' => $company->id,
        'name' => 'June 2026',
        'status' => PayrollPeriodStatus::Draft->value,
    ]);
});
