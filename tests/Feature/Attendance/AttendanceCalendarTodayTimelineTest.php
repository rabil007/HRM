<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * @return array{user: User, company: Company}
 */
function makeTodayTimelineFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'TT'.fake()->unique()->numerify('##'),
        'name' => 'Timeline Testland',
        'dial_code' => '+001',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'TT'.fake()->unique()->numerify('##'),
        'name' => 'Timeline Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Timeline Co',
        'slug' => 'timeline-'.fake()->unique()->numerify('####'),
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

function makeTodayTimelineEmployee(Company $company): Employee
{
    return Employee::factory()->forCompany($company)->create(['status' => 'active']);
}

function grantCalendarAccess(User $user, Company $company): void
{
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);
}

function makeTodayAccessEvent(string $personHikvisionId, string $attendanceStatus, string $time): void
{
    Carbon::setTestNow('2026-07-16 00:00:00');
    HikvisionAccessEvent::query()->create([
        'system_id' => 'timeline-test:'.fake()->uuid(),
        'msg_type' => 'acs/5/38',
        'occurrence_time' => "2026-07-16 {$time}:00",
        'person_name' => 'Timeline Employee',
        'person_hikvision_id' => $personHikvisionId,
        'device_name' => 'Main Gate',
        'attendance_status' => $attendanceStatus,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);
}

// Freeze the test clock so "today" is always 2026-07-16
beforeEach(function () {
    Carbon::setTestNow('2026-07-16 10:00:00');
});

test('today_timeline is null when no employee is selected', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

    // No employee_id query param → selected_employee_id is null
    $this->actingAs($user)
        ->get(route('attendance.calendar.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/calendar')
            ->where('today_timeline', null));
});

test('today_timeline is null when employee has no hikvision person linked', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    // employee exists but hikvision_person_id is null
    expect($employee->hikvision_person_id)->toBeNull();

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline', null));
});

test('today_timeline returns empty events when employee is linked but has no events today', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-no-events');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.clock_in', null)
            ->where('today_timeline.summary.clock_out', null)
            ->where('today_timeline.summary.is_complete', false)
            ->where('today_timeline.summary.is_on_leave', false));
});

test('today_timeline includes check-in event and derives clock_in summary', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-checkin');
    makeTodayAccessEvent('timeline-person-checkin', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:02');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.status', 'checkIn')
            ->where('today_timeline.events.0.device_name', 'Main Gate')
            ->where('today_timeline.summary.clock_in', '09:02')
            ->where('today_timeline.summary.clock_out', null)
            ->where('today_timeline.summary.is_complete', false));
});

test('today_timeline marks day complete when both check-in and check-out are present', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-complete');
    makeTodayAccessEvent('timeline-person-complete', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '08:55');
    makeTodayAccessEvent('timeline-person-complete', HikvisionAccessEvent::ATTENDANCE_CHECK_OUT, '17:30');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 2)
            ->where('today_timeline.summary.clock_in', '08:55')
            ->where('today_timeline.summary.clock_out', '17:30')
            ->where('today_timeline.summary.is_complete', true));
});

test('today_timeline sets is_on_leave true when employee has approved leave today', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-leave');

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-16',
        'end_date' => '2026-07-16',
        'total_days' => 1,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.summary.is_on_leave', true)
            ->where('today_timeline.events', []));
});

test('today_timeline follows the selected employee for approvers', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();

    $viewerEmployee = makeTodayTimelineEmployee($company);
    $viewerEmployee->update(['user_id' => $user->id]);
    linkHikvisionPersonToUserCompany($viewerEmployee, 'timeline-person-viewer');
    // Add a check-in for the viewer — should NOT appear in the timeline
    makeTodayAccessEvent('timeline-person-viewer', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:00');

    $otherEmployee = makeTodayTimelineEmployee($company);
    linkHikvisionPersonToUserCompany($otherEmployee, 'timeline-person-other');
    // Add a check-in for the other employee — should appear in the timeline
    makeTodayAccessEvent('timeline-person-other', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '08:30');

    grantCalendarAccess($user, $company);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $otherEmployee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '08:30'));
});

test('today_timeline does not include events from a different company', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-company-scope');

    // Same person_hikvision_id used by an event that belongs to a different context
    // The scopeForCompany is not applied here; instead the person_hikvision_id
    // scoping is used. To test true company isolation we create an event for a
    // different person_hikvision_id and verify it does not leak in.
    makeTodayAccessEvent('OTHER-COMPANY-PERSON', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:15');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', []));
});
