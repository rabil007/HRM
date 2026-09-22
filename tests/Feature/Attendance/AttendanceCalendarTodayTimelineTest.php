<?php

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use App\Support\Attendance\TodayAttendanceTimeline;
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
    return createAttendanceLeaveEmployee($company);
}

function grantCalendarAccess(User $user, Company $company): void
{
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);
}

function makeTodayAccessEvent(
    string $personHikvisionId,
    string $attendanceStatus,
    string $time,
    string $transactionSource = HikvisionAccessEvent::TRANSACTION_DEVICE,
    ?int $companyId = null,
): void {
    HikvisionAccessEvent::query()->create([
        'company_id' => $companyId ?? hikvisionTestCompany()->id,
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
        'company_id' => $company->id,
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
    createLeaveRequestRecord([
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
        'company_id' => $company->id,
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
        'company_id' => $company->id,
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

test('today_timeline shows production-style unlinked acs check-in for Mohammed Rabil T', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
        'employee_no' => '1034',
        'user_id' => $user->id,
    ]);
    grantCalendarAccess($user, $company);

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-production',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:rabil:2026-09-14:09:03',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'hikvision_person_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '09:03')
            ->where('today_timeline.events.0.status', 'checkIn')
            ->where('today_timeline.events.0.device_name', 'OMS-Door')
            ->where('today_timeline.events.0.transaction_source', 'device')
            ->where('today_timeline.summary.clock_in', '09:03')
            ->where('today_timeline.summary.status', 'checked_in')
            ->where('today_timeline.summary.event_count', 1));

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-09-14')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in?->format('H:i'))->toBe('09:03')
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT);
});

test('today_timeline still matches when person_hikvision_id is already populated', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = makeTodayTimelineEmployee($company);
    $employee->update([
        'user_id' => $user->id,
        'name' => 'Mohammed Rabil T',
    ]);
    grantCalendarAccess($user, $company);

    linkHikvisionPersonToUserCompany($employee, 'hv-rabil-linked', [
        'full_name' => 'Mohammed Rabil T',
    ]);

    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:rabil-linked:09:03',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => 'hv-rabil-linked',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '09:03')
            ->where('today_timeline.summary.clock_in', '09:03'));
});

test('today_timeline does not guess an ambiguous unlinked Mohammed Rabil alias', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

    $employeeA = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
    ]);
    $employeeB = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil K',
    ]);

    $personA = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-t',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $personB = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-k',
        'full_name' => 'Mohammed Rabil K',
    ]);
    $employeeA->update(['hikvision_person_id' => $personA->id]);
    $employeeB->update(['hikvision_person_id' => $personB->id]);

    $event = HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:rabil-ambiguous:09:03',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'hikvision_person_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employeeA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.status', 'no_activity'));

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employeeB->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.status', 'no_activity'));

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );

    $recordA = AttendanceRecord::query()
        ->where('employee_id', $employeeA->id)
        ->whereDate('date', '2026-09-14')
        ->first();
    $recordB = AttendanceRecord::query()
        ->where('employee_id', $employeeB->id)
        ->whereDate('date', '2026-09-14')
        ->first();

    expect($recordA?->clock_in)->toBeNull()
        ->and($recordA?->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($recordB?->clock_in)->toBeNull()
        ->and($recordB?->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($event->fresh()->person_hikvision_id)->toBeNull()
        ->and($event->fresh()->hikvision_person_id)->toBeNull();
});

test('today_timeline linked person id remains authoritative when another employee has a similar name', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

    $employeeA = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
    ]);
    $employeeB = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil K',
    ]);

    $personA = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-linked-t',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $personB = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-linked-k',
        'full_name' => 'Mohammed Rabil K',
    ]);
    $employeeA->update(['hikvision_person_id' => $personA->id]);
    $employeeB->update(['hikvision_person_id' => $personB->id]);

    HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:rabil-linked-authoritative:09:03',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => 'hv-rabil-linked-t',
        'hikvision_person_id' => $personA->id,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employeeA->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '09:03')
            ->where('today_timeline.summary.clock_in', '09:03'));

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employeeB->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.status', 'no_activity'));

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );

    $recordA = AttendanceRecord::query()
        ->where('employee_id', $employeeA->id)
        ->whereDate('date', '2026-09-14')
        ->first();
    $recordB = AttendanceRecord::query()
        ->where('employee_id', $employeeB->id)
        ->whereDate('date', '2026-09-14')
        ->first();

    expect($recordA)->not->toBeNull()
        ->and($recordA->clock_in?->format('H:i'))->toBe('09:03')
        ->and($recordA->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($recordB?->clock_in)->toBeNull()
        ->and($recordB?->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC);
});

test('today_timeline never includes cross-company unlinked name matches', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
        'user_id' => $user->id,
    ]);
    grantCalendarAccess($user, $company);

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-local',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    $otherCompany = additionalHikvisionTestCompany($company, 'timeline-cross-'.fake()->unique()->numerify('####'));

    HikvisionAccessEvent::query()->create([
        'company_id' => $otherCompany->id,
        'system_id' => 'acs:cross-company-rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.status', 'no_activity'));

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-09-14')
        ->first();

    expect($record?->clock_in)->toBeNull()
        ->and($record?->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC);
});

test('today_timeline unique unlinked alias is not poisoned by another company', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

    $employeeT = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
    ]);
    $employeeK = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil K',
    ]);

    $personT = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-ambiguous-t',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $personK = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-ambiguous-k',
        'full_name' => 'Mohammed Rabil K',
    ]);
    $employeeT->update(['hikvision_person_id' => $personT->id]);
    $employeeK->update(['hikvision_person_id' => $personK->id]);

    $otherCompany = additionalHikvisionTestCompany($company, 'timeline-unique-'.fake()->unique()->numerify('####'));
    $otherUser = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $otherCompany->id,
        'user_id' => $otherUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCalendarAccess($otherUser, $otherCompany);

    $otherEmployee = createAttendanceLeaveEmployee($otherCompany, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
    ]);
    $otherPerson = HikvisionPerson::query()->create([
        'company_id' => $otherCompany->id,
        'person_id' => 'hv-rabil-other-unique',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $otherEmployee->update(['hikvision_person_id' => $otherPerson->id]);

    HikvisionAccessEvent::query()->create([
        'company_id' => $otherCompany->id,
        'system_id' => 'acs:other-company-unique-rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('attendance.calendar.index', ['employee_id' => $employeeT->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('today_timeline.events', [])
            ->where('today_timeline.summary.status', 'no_activity'));

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('attendance.calendar.index', ['employee_id' => $otherEmployee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '09:03')
            ->where('today_timeline.summary.clock_in', '09:03'));

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );
    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $otherCompany->id,
        Carbon::parse('2026-09-14 00:00:00', 'Asia/Dubai'),
        Carbon::parse('2026-09-14 23:59:59', 'Asia/Dubai'),
    );

    $localRecord = AttendanceRecord::query()
        ->where('employee_id', $employeeT->id)
        ->whereDate('date', '2026-09-14')
        ->first();
    $otherRecord = AttendanceRecord::query()
        ->where('employee_id', $otherEmployee->id)
        ->whereDate('date', '2026-09-14')
        ->first();

    expect($localRecord?->clock_in)->toBeNull()
        ->and($localRecord?->source)->not->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($otherRecord)->not->toBeNull()
        ->and($otherRecord->clock_in?->format('H:i'))->toBe('09:03')
        ->and($otherRecord->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC);
});

test('today_timeline hydrates only the selected employee punches including unique unlinked fallbacks', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-14 10:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeTodayTimelineFixtures();
    grantCalendarAccess($user, $company);

    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
        'employee_no' => '1034',
        'user_id' => $user->id,
    ]);
    $unrelatedEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'name' => 'Unrelated Colleague',
    ]);

    $person = HikvisionPerson::query()->create([
        'company_id' => $company->id,
        'person_id' => 'hv-rabil-scoped',
        'full_name' => 'Mohammed Rabil T',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);
    linkHikvisionPersonToUserCompany($unrelatedEmployee, 'hv-unrelated-colleague', [
        'full_name' => 'Unrelated Colleague',
    ]);

    $matched = HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:rabil-scoped:09:03',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'hikvision_person_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);
    $unrelatedLinked = HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:unrelated-linked:08:00',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 08:00:00',
        'person_name' => 'Unrelated Colleague',
        'person_hikvision_id' => 'hv-unrelated-colleague',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);
    $unrelatedUnlinked = HikvisionAccessEvent::query()->create([
        'company_id' => $company->id,
        'system_id' => 'acs:unrelated-unlinked:08:15',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 08:15:00',
        'person_name' => 'Unrelated Colleague',
        'person_hikvision_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $otherCompany = additionalHikvisionTestCompany($company, 'timeline-scope-'.fake()->unique()->numerify('####'));
    $crossCompany = HikvisionAccessEvent::query()->create([
        'company_id' => $otherCompany->id,
        'system_id' => 'acs:cross-company-scoped-rabil',
        'msg_type' => 'acs/5/75',
        'occurrence_time' => '2026-09-14 09:03:00',
        'person_name' => 'Mohammed Rabil',
        'person_hikvision_id' => null,
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $hydratedIds = [];
    HikvisionAccessEvent::retrieved(function (HikvisionAccessEvent $event) use (&$hydratedIds): void {
        $hydratedIds[] = (int) $event->id;
    });

    try {
        $timeline = app(TodayAttendanceTimeline::class)->forEmployee($company->id, $employee->id);
    } finally {
        HikvisionAccessEvent::getEventDispatcher()->forget(
            'eloquent.retrieved: '.HikvisionAccessEvent::class,
        );
    }

    expect($timeline)->not->toBeNull()
        ->and($timeline['date'])->toBe('2026-09-14')
        ->and($timeline['timezone'])->toBe('Asia/Dubai')
        ->and($timeline['events'])->toHaveCount(1)
        ->and($timeline['events'][0]['time'])->toBe('09:03')
        ->and($timeline['events'][0]['status'])->toBe('checkIn')
        ->and($timeline['summary']['clock_in'])->toBe('09:03')
        ->and($timeline['summary']['status'])->toBe('checked_in')
        ->and($hydratedIds)->toBe([(int) $matched->id])
        ->and($hydratedIds)->not->toContain((int) $unrelatedLinked->id)
        ->and($hydratedIds)->not->toContain((int) $unrelatedUnlinked->id)
        ->and($hydratedIds)->not->toContain((int) $crossCompany->id);

    $this->actingAs($user)
        ->get(route('attendance.calendar.index', ['employee_id' => $employee->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('today_timeline.events', 1)
            ->where('today_timeline.events.0.time', '09:03')
            ->where('today_timeline.summary.clock_in', '09:03')
            ->where('today_timeline.summary.status', 'checked_in'));
});
