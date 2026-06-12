<?php

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
function makeAttendanceCalendarFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Calendarland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AC'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Calendar Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Calendar Co',
        'slug' => 'calendar-'.fake()->unique()->numerify('####'),
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

/**
 * @return array{employee: Employee, leaveType: LeaveType}
 */
function makeAttendanceCalendarActors(Company $company): array
{
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);

    return ['employee' => $employee, 'leaveType' => $leaveType];
}

test('guests cannot access attendance calendar page', function () {
    $this->get(route('attendance.calendar.index'))->assertRedirect(route('login'));
});

test('authorized users can view attendance calendar page', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/calendar')
            ->where('year', 2026)
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $employee->id)
            ->where('approved_leaves.0.leave_type.id', $leaveType->id));
});

test('attendance calendar exposes pending request count for selected year', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-03',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2027-01-02',
        'end_date' => '2027-01-03',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pending_request_count', 1));
});

test('attendance calendar only includes approved leave requests', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    $statuses = ['pending', 'rejected', 'cancelled'];

    foreach ($statuses as $status) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-02',
            'total_days' => 2,
            'status' => $status,
        ]);
    }

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-04-01',
        'end_date' => '2026-04-02',
        'total_days' => 2,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.start_date', '2026-04-01'));
});

test('users without approve permission only see their own approved leaves on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $ownEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-02',
        'total_days' => 2,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-05-10',
        'end_date' => '2026-05-12',
        'total_days' => 3,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('approved_leaves', 1)
            ->where('approved_leaves.0.employee.id', $ownEmployee->id));
});

test('users with approve permission see all approved leaves on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $ownEmployee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    ['employee' => $otherEmployee] = makeAttendanceCalendarActors($company);

    $ownEmployee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    foreach ([$ownEmployee, $otherEmployee] as $employee) {
        LeaveRequest::query()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'total_days' => 2,
            'status' => 'approved',
            'approved_by' => $user->id,
            'decided_at' => now(),
        ]);
    }

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('approved_leaves', 2));
});

test('cross year approved leave appears in both years on calendar', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceCalendarFixtures();
    ['employee' => $employee, 'leaveType' => $leaveType] = makeAttendanceCalendarActors($company);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.view']);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2025-12-30',
        'end_date' => '2026-01-02',
        'total_days' => 4,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->get(route('attendance.calendar.index', ['year' => 2025]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('approved_leaves', 1));

    $this->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('approved_leaves', 1));
});
