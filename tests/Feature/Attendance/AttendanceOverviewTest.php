<?php

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAttendanceOverviewFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'OV'.fake()->unique()->numerify('##'),
        'name' => 'Overview Land',
        'dial_code' => '+777',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'OV'.fake()->unique()->numerify('##'),
        'name' => 'Overview Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Overview Co',
        'slug' => 'overview-'.fake()->unique()->numerify('####'),
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

test('guests cannot access the attendance overview page', function () {
    $this->get('/attendance/overview')->assertRedirect(route('login'));
});

test('users without attendance permissions get 403 on attendance overview', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceOverviewFixtures();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertForbidden();
});

test('users with attendance.records.view can access the overview', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceOverviewFixtures();

    grantCompanyPermissions($user, $company, ['attendance.records.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/overview')
            ->has('summary')
            ->has('can')
            ->where('can.view_records', true)
        );
});

test('users with attendance.leave-requests.view can access the overview', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceOverviewFixtures();

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/overview')
            ->has('summary')
            ->where('can.view_leave_requests', true)
        );
});

test('attendance overview summary contains correct structure', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceOverviewFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.leave-requests.view',
    ]);

    // Seed some attendance records
    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => now()->startOfMonth()->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'hours_worked' => 8.0,
        'overtime_hours' => 0,
        'late_minutes' => 0,
    ]);

    AttendanceRecord::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'date' => now()->startOfMonth()->addDay()->toDateString(),
        'status' => AttendanceRecord::STATUS_LATE,
        'source' => AttendanceRecord::SOURCE_BIOMETRIC,
        'hours_worked' => 7.5,
        'overtime_hours' => 0,
        'late_minutes' => 20,
    ]);

    // Seed a leave type and leave request
    $leaveType = LeaveType::factory()->for($company)->create([
        'name' => 'Annual Leave',
        'code' => 'AL',
        'status' => 'active',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->startOfMonth()->addDays(1)->toDateString(),
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/overview')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/overview')
            ->where('summary.this_month_total', 2)
            ->where('summary.this_month_present', 1)
            ->where('summary.this_month_late', 1)
            ->where('summary.this_month_total_late_minutes', 20)
            ->where('summary.leave_pending', 1)
            ->has('summary.monthly_trend', 6)
            ->has('summary.leave_monthly_trend', 6)
            ->has('summary.status_breakdown')
            ->has('summary.source_breakdown')
        );
});
