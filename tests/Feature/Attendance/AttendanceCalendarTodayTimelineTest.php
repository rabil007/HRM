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
function makeTodayTimelineFixtures(?string $timezone = 'Asia/Dubai'): array
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
        'timezone' => $timezone,
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

function makeTodayAccessEvent(
    string $personHikvisionId,
    string $attendanceStatus,
    string $time,
    string $transactionSource = HikvisionAccessEvent::TRANSACTION_DEVICE,
): void {
    HikvisionAccessEvent::query()->create([
        'system_id' => 'timeline-test:'.fake()->uuid(),
        'msg_type' => 'acs/5/38',
        'occurrence_time' => "2026-07-16 {$time}:00",
        'person_name' => 'Timeline Employee',
        'person_hikvision_id' => $personHikvisionId,
        'device_name' => 'Main Gate',
        'attendance_status' => $attendanceStatus,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => $transactionSource,
        'fetched_at' => now(),
    ]);
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-07-16 10:00:00', 'Asia/Dubai'));
});

test('today_timeline is null when no employee is selected', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

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
            ->where('today_timeline.date', '2026-07-16')
            ->where('today_timeline.is_today', true)
            ->where('today_timeline.timezone', 'Asia/Dubai')
            ->where('today_timeline.window_start', '09:00')
            ->where('today_timeline.window_end', '18:00')
            ->where('today_timeline.summary.clock_in', null)
            ->where('today_timeline.summary.clock_out', null)
            ->where('today_timeline.summary.is_complete', false)
            ->where('today_timeline.summary.is_on_leave', false)
            ->where('today_timeline.summary.status', 'no_activity')
            ->where('today_timeline.summary.event_count', 0)
            ->where('today_timeline.summary.elapsed_minutes', null));
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
            ->where('today_timeline.events.0.transaction_source', 'device')
            ->where('today_timeline.summary.clock_in', '09:02')
            ->where('today_timeline.summary.clock_out', null)
            ->where('today_timeline.summary.is_complete', false)
            ->where('today_timeline.summary.status', 'checked_in')
            ->where('today_timeline.summary.event_count', 1)
            ->where('today_timeline.summary.elapsed_minutes', 58));
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
            ->where('today_timeline.summary.is_complete', true)
            ->where('today_timeline.summary.status', 'checked_out')
            ->where('today_timeline.window_start', '08:25')
            ->where('today_timeline.window_end', '18:00')
            ->where('today_timeline.summary.elapsed_minutes', 515));
});

test('today_timeline treats second check-in as check-out when no explicit check-out exists', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-dual-in');
    makeTodayAccessEvent('timeline-person-dual-in', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:00');
    makeTodayAccessEvent('timeline-person-dual-in', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '17:15');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 2)
            ->where('today_timeline.summary.clock_in', '09:00')
            ->where('today_timeline.summary.clock_out', '17:15')
            ->where('today_timeline.summary.is_complete', true)
            ->where('today_timeline.summary.status', 'checked_out'));
});

test('today_timeline ignores events without check-in or check-out status', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-noise');
    makeTodayAccessEvent('timeline-person-noise', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:05');
    HikvisionAccessEvent::query()->create([
        'system_id' => 'timeline-test:'.fake()->uuid(),
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-07-16 10:30:00',
        'person_name' => 'Timeline Employee',
        'person_hikvision_id' => 'timeline-person-noise',
        'device_name' => 'Side Door',
        'attendance_status' => null,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.status', 'checkIn')
            ->where('today_timeline.summary.event_count', 1));
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
            ->where('today_timeline.summary.status', 'on_leave')
            ->where('today_timeline.events', []));
});

test('today_timeline follows the selected employee for approvers', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();

    $viewerEmployee = makeTodayTimelineEmployee($company);
    $viewerEmployee->update(['user_id' => $user->id]);
    linkHikvisionPersonToUserCompany($viewerEmployee, 'timeline-person-viewer');
    makeTodayAccessEvent('timeline-person-viewer', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:00');

    $otherEmployee = makeTodayTimelineEmployee($company);
    linkHikvisionPersonToUserCompany($otherEmployee, 'timeline-person-other');
    makeTodayAccessEvent('timeline-person-other', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '08:30');

    grantCalendarAccess($user, $company);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $otherEmployee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '08:30'));
});

test('today_timeline does not include events from a different company person', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-company-scope');
    makeTodayAccessEvent('OTHER-COMPANY-PERSON', HikvisionAccessEvent::ATTENDANCE_CHECK_IN, '09:15');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', []));
});

test('today_timeline uses company timezone for today date', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures('America/New_York');
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-tz');

    // Still 16 Jul evening in New York while already 17 Jul morning in Dubai.
    Carbon::setTestNow(Carbon::parse('2026-07-17 02:00:00', 'Asia/Dubai'));

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.timezone', 'America/New_York')
            ->where('today_timeline.date', '2026-07-16')
            ->where('today_timeline.is_today', true));
});

test('today_timeline can load a previous day via timeline_date', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-prev');

    HikvisionAccessEvent::query()->create([
        'system_id' => 'timeline-test:'.fake()->uuid(),
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-07-15 09:15:00',
        'person_name' => 'Timeline Employee',
        'person_hikvision_id' => 'timeline-person-prev',
        'device_name' => 'Main Gate',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);
    HikvisionAccessEvent::query()->create([
        'system_id' => 'timeline-test:'.fake()->uuid(),
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-07-15 17:40:00',
        'person_name' => 'Timeline Employee',
        'person_hikvision_id' => 'timeline-person-prev',
        'device_name' => 'Main Gate',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', [
            'employee_id' => $employee->id,
            'timeline_date' => '2026-07-15',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.date', '2026-07-15')
            ->where('today_timeline.is_today', false)
            ->has('today_timeline.events', 2)
            ->where('today_timeline.summary.clock_in', '09:15')
            ->where('today_timeline.summary.clock_out', '17:40')
            ->where('today_timeline.summary.is_complete', true));
});

test('today_timeline clamps future timeline_date to today', function () {
    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update(['user_id' => $user->id]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'timeline-person-future');

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', [
            'employee_id' => $employee->id,
            'timeline_date' => '2026-07-20',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.date', '2026-07-16')
            ->where('today_timeline.is_today', true));
});
