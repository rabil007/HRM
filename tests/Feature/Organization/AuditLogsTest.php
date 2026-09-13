<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
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
        'causer_type' => User::class,
        'causer_id' => $user->id,
    ]);

    $activity = Activity::query()->where('subject_type', Branch::class)->where('subject_id', $branch->id)->latest('id')->first();
    expect($activity)->not->toBeNull();
});

test('automatic model changes are not logged without an authenticated user', function () {
    $country = Country::query()->create([
        'code' => 'SYS',
        'name' => 'Systemland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'SYS',
        'name' => 'System Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'System Audit Co',
        'slug' => 'system-audit-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Automated Branch',
        'code' => 'AUTO',
        'address' => null,
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => null,
        'email' => null,
        'is_headquarters' => false,
        'status' => 'active',
    ]);

    expect(Activity::query()
        ->where('subject_type', Branch::class)
        ->where('subject_id', $branch->id)
        ->count())->toBe(0);
});

test('activity logs page excludes legacy and explicit system activity', function () {
    $country = Country::query()->create([
        'code' => 'VIS',
        'name' => 'Visibility Land',
        'dial_code' => '+997',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VIS',
        'name' => 'Visibility Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Visibility Co',
        'slug' => 'visibility-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['audit.view']);
    $this->actingAs($user);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Human Branch',
        'code' => 'HUM',
        'address' => null,
        'city' => 'Dubai',
        'country' => 'UAE',
        'phone' => null,
        'email' => null,
        'is_headquarters' => false,
        'status' => 'active',
    ]);

    $this->app['auth']->logout();

    activity()
        ->performedOn($branch)
        ->event('synced')
        ->tap(function (Activity $activity) use ($company): void {
            $activity->company_id = $company->id;
        })
        ->log('Automated system sync');

    $this->actingAs($user);

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/activity-logs')
            ->has('logs', 1)
            ->where('logs.0.causer.id', $user->id)
            ->where('logs.0.subject_id', $branch->id)
            ->where('pagination.total', 1),
        );

    expect(Activity::query()
        ->where('company_id', $company->id)
        ->whereNull('causer_id')
        ->where('description', 'Automated system sync')
        ->exists())->toBeTrue();
});
