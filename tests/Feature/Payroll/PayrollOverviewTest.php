<?php

test('guests cannot access the payroll overview page', function () {
    $this->get(route('payroll.overview'))->assertRedirect(route('login'));
});

test('users without payroll overview permission get 403', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.overview'))
        ->assertForbidden();
});

test('users with only payroll.periods.view cannot access the overview', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.overview'))
        ->assertForbidden();
});

test('users with only payroll.crew_timesheets.view cannot access the overview', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    grantCompanyPermissions($user, $company, ['payroll.crew_timesheets.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.overview'))
        ->assertForbidden();
});

test('users with payroll.overview.view are authorized for the overview', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();

    grantCompanyPermissions($user, $company, [
        'payroll.overview.view',
        'payroll.periods.view',
    ]);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.overview'));

    expect($response->getStatusCode())->not->toBe(403);
});
