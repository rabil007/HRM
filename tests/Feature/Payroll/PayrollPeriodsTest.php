<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\PayrollPeriod;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access payroll hub', function () {
    $this->get(route('payroll.index'))
        ->assertRedirect(route('login'));
});

test('users without permission cannot access payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.index'))
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
        ->get(route('payroll.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->has('periods', 0)
            ->has('summary')
            ->where('summary.total_periods', 0)
            ->where('permissions.create_period', true)
            ->where('permissions.view_crew_timesheets', true));

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'June 2026',
            'payroll_category' => 'crew',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
            'notes' => 'Monthly payroll',
        ])
        ->assertRedirect(route('payroll.index'));

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
        ->post(route('payroll.periods.store'), [
            'name' => 'June 2026 Crew',
            'payroll_category' => 'crew',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
        ])
        ->assertRedirect();

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'June 2026 Office',
            'payroll_category' => 'office',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'payment_date' => '2026-07-05',
        ])
        ->assertRedirect();

    expect(PayrollPeriod::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('new pay period can be created with same start date if previous period was cancelled', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.create']);

    PayrollPeriod::factory()->for($company)->create([
        'name' => 'Cancelled Run',
        'payroll_category' => 'crew',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => PayrollPeriodStatus::Cancelled,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.periods.store'), [
            'name' => 'Demo Crew January 2026 New',
            'payroll_category' => 'crew',
            'start_date' => '2026-01-01',
            'end_date' => '2026-01-31',
            'payment_date' => '2026-02-05',
        ])
        ->assertRedirect();

    expect(PayrollPeriod::query()->where('company_id', $company->id)->where('start_date', '2026-01-01')->count())->toBe(2);
});

test('payroll hub can filter periods by payroll category', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    PayrollPeriod::factory()->for($company)->create(['name' => 'June Crew', 'payroll_category' => 'crew']);
    PayrollPeriod::factory()->for($company)->office()->create(['name' => 'June Office']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.index', ['category' => 'office']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->has('periods', 1)
            ->where('periods.0.name', 'June Office')
            ->where('filters.category', 'office')
            ->where('filters.status', '')
            ->where('filters.date_from', '')
            ->where('filters.date_to', ''));
});

test('payroll hub can filter periods by overlapping period dates', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    PayrollPeriod::factory()->for($company)->create([
        'name' => 'June 2026',
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
    ]);
    PayrollPeriod::factory()->for($company)->create([
        'name' => 'May 2026',
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.index', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-30',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->has('periods', 1)
            ->where('periods.0.name', 'June 2026')
            ->where('filters.date_from', '2026-06-01')
            ->where('filters.date_to', '2026-06-30'));
});

test('payroll hub can filter periods by status', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    PayrollPeriod::factory()->for($company)->create([
        'name' => 'Draft Run',
        'status' => PayrollPeriodStatus::Draft,
    ]);
    PayrollPeriod::factory()->for($company)->create([
        'name' => 'Processing Run',
        'status' => PayrollPeriodStatus::Processing,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('payroll.index', ['status' => PayrollPeriodStatus::Processing->value]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('payroll/index')
            ->has('periods', 1)
            ->where('periods.0.name', 'Processing Run')
            ->where('filters.status', PayrollPeriodStatus::Processing->value));
});

test('legacy payroll period routes redirect to payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll-periods.index'))
        ->assertRedirect(route('payroll.index'));
});

test('legacy organization payroll index redirects to payroll hub', function () {
    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['payroll.periods.view']);

    $period = PayrollPeriod::factory()->for($company)->create();

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll.index'))
        ->assertRedirect(route('payroll.index'));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.payroll.show', $period))
        ->assertRedirect(route('payroll.show', $period));
});
