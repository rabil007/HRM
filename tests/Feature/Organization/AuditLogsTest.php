<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('guests cannot access activity logs page', function () {
    $this->get('/organization/activity-logs')->assertRedirect(route('login'));
});

test('users without audit permission cannot access activity logs page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'AUD',
        'name' => 'Auditland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'AUD',
        'name' => 'Audit Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Audit Co',
        'slug' => 'audit-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->get('/organization/activity-logs')->assertForbidden();
});

test('company detail does not expose recent activity without audit permission', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'CMP',
        'name' => 'CompanyLand',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CMP',
        'name' => 'Company Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->get(route('organization.companies.show', $company))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []),
        );
});

test('activity log is recorded for branch creation', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, ['branches.create', 'branches.view', 'audit.view']);

    $this->post('/organization/branches', [
        'name' => 'HQ',
        'code' => 'HQ',
        'address' => 'Street 1',
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => '+971500000000',
        'email' => 'hq@example.com',
        'is_headquarters' => true,
        'status' => 'active',
    ])->assertRedirect();

    $branch = Branch::query()->where('company_id', $company->id)->where('code', 'HQ')->firstOrFail();

    $this->assertDatabaseHas('activity_log', [
        'company_id' => $company->id,
        'event' => 'created',
        'subject_type' => Branch::class,
        'subject_id' => $branch->id,
    ]);

    $activity = Activity::query()->where('subject_type', Branch::class)->where('subject_id', $branch->id)->latest('id')->first();
    expect($activity)->not->toBeNull();
});
