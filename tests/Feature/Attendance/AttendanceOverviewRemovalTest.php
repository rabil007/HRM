<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Navigation\NavigationDestinationCatalog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAttendanceNavigationFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AN'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Nav Land',
        'dial_code' => '+778',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AN'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Nav Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Attendance Nav Co',
        'slug' => 'attendance-nav-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['user' => $user, 'company' => $company];
}

test('attendance overview route is unavailable', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceNavigationFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.overview.view',
        'attendance.records.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertNotFound();
});

test('attendance overview is not a favoritable navigation destination', function () {
    $keys = NavigationDestinationCatalog::keys();

    expect($keys)->not->toContain('attendance.overview')
        ->and($keys)->toContain('attendance.records')
        ->and($keys)->toContain('attendance.calendar');

    $attendanceLabels = array_column(array_values(array_filter(
        NavigationDestinationCatalog::all(),
        fn (array $destination): bool => $destination['group'] === 'Attendance',
    )), 'label');

    expect($attendanceLabels)->not->toContain('Overview')
        ->and($attendanceLabels)->toContain('Attendance records')
        ->and($attendanceLabels)->toContain('Calendar');
});

test('attendance records remain accessible with attendance.records.view', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceNavigationFixtures();

    grantCompanyPermissions($user, $company, ['attendance.records.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/records'));
});

test('attendance calendar remains accessible with leave-requests.view', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceNavigationFixtures();

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/calendar'));
});

test('attendance records stay company scoped after overview removal', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceNavigationFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Other Attendance Co',
        'slug' => 'other-attendance-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $otherCompany->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    grantCompanyPermissions($user, $company, ['attendance.records.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get('/attendance/records')
        ->assertForbidden();
});
