<?php

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\User;
use App\Services\HikvisionService;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAttendanceRecordsFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AR'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Recordland',
        'dial_code' => '+998',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AR'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Attendance Co',
        'slug' => 'attendance-'.fake()->unique()->numerify('####'),
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
 * @return array<string, mixed>
 */
function validAttendanceRecordPayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'clock_in' => '2026-06-10 08:00:00',
        'clock_out' => '2026-06-10 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'late_minutes' => 0,
        'notes' => 'Manual entry',
    ], $overrides);
}

test('guests cannot access attendance records page', function () {
    $this->get('/attendance/records')->assertRedirect(route('login'));
});

test('authorized users can view create update and delete attendance records', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $employee->update(['user_id' => $user->id]);
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.create',
        'attendance.records.update',
        'attendance.records.delete',
        'attendance.records.manage',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/records')
            ->has('records')
            ->has('can')
        );

    $redirectQuery = [
        'employee_id' => $employee->id,
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_MANUAL,
    ];

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/records', validAttendanceRecordPayload($employee))
        ->assertRedirect(route('attendance.records.index', $redirectQuery));

    $record = AttendanceRecord::query()->where('employee_id', $employee->id)->first();
    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_MANUAL)
        ->and((float) $record->hours_worked)->toBe(9.0);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/records/{$record->id}", validAttendanceRecordPayload($employee, [
            'clock_out' => '2026-06-10 16:00:00',
            'notes' => 'Updated notes',
        ]))
        ->assertRedirect(route('attendance.records.index', $redirectQuery));

    expect($record->fresh()->notes)->toBe('Updated notes')
        ->and((float) $record->fresh()->hours_worked)->toBe(8.0);

    $this->withSession(['current_company_id' => $company->id])
        ->delete("/attendance/records/{$record->id}")
        ->assertRedirect(route('attendance.records.index'));

    expect(AttendanceRecord::query()->find($record->id))->toBeNull();
});

test('users without manage permission only see their own records', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $ownEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
    ]);
    $otherEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    AttendanceRecord::factory()->forEmployee($ownEmployee)->create(['date' => '2026-06-10']);
    AttendanceRecord::factory()->forEmployee($otherEmployee)->create(['date' => '2026-06-10']);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.records.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records?date_from=2026-06-01&date_to=2026-06-30')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('records', 1)
            ->where('records.0.employee.id', $ownEmployee->id)
        );
});

test('users cannot update another employees record without manage permission', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $ownEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
    ]);
    $otherEmployee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $otherRecord = AttendanceRecord::factory()->forEmployee($otherEmployee)->create(['date' => '2026-06-10']);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.update',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/records/{$otherRecord->id}", validAttendanceRecordPayload($otherEmployee))
        ->assertNotFound();
});

test('hikvision sync creates attendance records from access events', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Synced Employee',
    ]);

    $person = HikvisionPerson::query()->create([
        'person_id' => 'sync-person-1',
        'full_name' => 'Synced Employee',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'sync:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-10 08:00:00',
        'person_name' => 'Synced Employee',
        'person_hikvision_id' => 'sync-person-1',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'sync:checkout',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-10 17:00:00',
        'person_name' => 'Synced Employee',
        'person_hikvision_id' => 'sync-person-1',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-10 00:00:00'),
        Carbon::parse('2026-06-10 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-10')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and((float) $record->hours_worked)->toBe(9.0);
});

test('hikvision sync does not overwrite manual records', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Manual Employee',
    ]);

    $person = HikvisionPerson::query()->create([
        'person_id' => 'manual-person-1',
        'full_name' => 'Manual Employee',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-10',
        'source' => AttendanceRecord::SOURCE_MANUAL,
        'clock_in' => '2026-06-10 09:30:00',
        'clock_out' => '2026-06-10 18:30:00',
        'hours_worked' => 9,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'manual:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-10 08:00:00',
        'person_name' => 'Manual Employee',
        'person_hikvision_id' => 'manual-person-1',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    $this->actingAs($user);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-10 00:00:00'),
        Carbon::parse('2026-06-10 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-10')
        ->first();

    expect($record->source)->toBe(AttendanceRecord::SOURCE_MANUAL)
        ->and($record->clock_in?->format('H:i'))->toBe('09:30');
});

test('hikvision sync creates absent records when no punches on a working day', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Absent Employee',
    ]);

    $person = HikvisionPerson::query()->create([
        'person_id' => 'absent-person-1',
        'full_name' => 'Absent Employee',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-10 00:00:00'),
        Carbon::parse('2026-06-10 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-10')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in)->toBeNull()
        ->and($record->clock_out)->toBeNull()
        ->and($record->status)->toBe(AttendanceRecord::STATUS_ABSENT)
        ->and($record->source)->toBeNull();
});

test('hikvision sync marks non working days as weekend when no punches', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Weekend Employee',
    ]);

    $person = HikvisionPerson::query()->create([
        'person_id' => 'weekend-person-1',
        'full_name' => 'Weekend Employee',
    ]);
    $employee->update(['hikvision_person_id' => $person->id]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-14 00:00:00'),
        Carbon::parse('2026-06-14 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-14')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in)->toBeNull()
        ->and($record->clock_out)->toBeNull()
        ->and($record->status)->toBe(AttendanceRecord::STATUS_WEEKEND)
        ->and($record->source)->toBeNull();
});

test('hikvision sync matches events using linked hikvision full name when employee name differs', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'alias-person-1',
        'full_name' => 'Mohammed Rabil',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Mohammed Rabil T',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'alias:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-12 08:00:00',
        'person_name' => 'Mohammed Rabil',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'alias:checkout',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-12 17:39:00',
        'person_name' => 'Mohammed Rabil',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-12 00:00:00'),
        Carbon::parse('2026-06-12 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-12')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in)->not->toBeNull()
        ->and($record->clock_out)->not->toBeNull()
        ->and((float) $record->hours_worked)->toBe(9.65);
});

test('hikvision sync matches events when access event name omits trailing initial from linked full name', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'suffix-person-1',
        'full_name' => 'Mohammed Rabil T',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'rabil',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'suffix:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-12 08:53:53',
        'person_name' => 'Mohammed Rabil',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'suffix:checkout',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-12 17:39:44',
        'person_name' => 'Mohammed Rabil',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-12 00:00:00'),
        Carbon::parse('2026-06-12 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-12')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in)->not->toBeNull()
        ->and($record->clock_out)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC);
});

test('hikvision sync creates mobile attendance records from mobile app access events', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'mobile-person-1',
        'person_code' => '42',
        'full_name' => 'MOHAMED ABDALLA JAMAL',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Mohamed Abdalla',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'mobile:checkin',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-17 08:57:00',
        'person_name' => 'MOHAMED ABDALLA JAMAL',
        'person_hikvision_id' => 'mobile-person-1',
        'device_name' => 'Mobile App',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'raw_payload' => [
            'fullName' => 'MOHAMED ABDALLA JAMAL',
            'personCode' => '42',
            'clockInSource' => 3,
        ],
        'fetched_at' => now(),
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'mobile:checkout',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-17 19:40:00',
        'person_name' => 'MOHAMED ABDALLA JAMAL',
        'person_hikvision_id' => 'mobile-person-1',
        'device_name' => 'Mobile App',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'raw_payload' => [
            'fullName' => 'MOHAMED ABDALLA JAMAL',
            'personCode' => '42',
            'clockOutSource' => 3,
        ],
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-17 00:00:00'),
        Carbon::parse('2026-06-17 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-17')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_MOBILE)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and($record->clock_in?->format('H:i'))->toBe('08:57')
        ->and($record->clock_out?->format('H:i'))->toBe('19:40');
});

test('hikvision sync matches mobile access events by person code when names differ', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'mobile-code-person-1',
        'person_code' => '7',
        'full_name' => 'Mathew Dominic',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Mathew D.',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'mobile-code:checkin',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-17 09:15:00',
        'person_name' => 'Mathew',
        'device_name' => 'Mobile App',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'raw_payload' => [
            'fullName' => 'Mathew',
            'personCode' => '7',
            'clockInSource' => 3,
        ],
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-17 00:00:00'),
        Carbon::parse('2026-06-17 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-17')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_MOBILE)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and($record->clock_in?->format('H:i'))->toBe('09:15');
});

test('attendance sync updates stale biometric record when mobile events were backfilled later', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'adham-person-12',
        'person_code' => '12',
        'full_name' => 'Adham Bassiony',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Adham Bassiony',
        'hikvision_person_id' => $person->id,
    ]);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-19',
        'clock_in' => '2026-06-19 08:40:43',
        'clock_out' => null,
        'hours_worked' => null,
        'source' => AttendanceRecord::SOURCE_BIOMETRIC,
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'adham:door:checkin',
        'msg_type' => 'webhook/event/110013',
        'occurrence_time' => '2026-06-19 08:40:43',
        'person_name' => 'Adham',
        'person_hikvision_id' => 'adham-person-12',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'raw_payload' => ['name' => 'Adham', 'employeeNoString' => '12'],
        'fetched_at' => '2026-06-19 08:41:03',
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'adham:mobile:checkin',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-19 08:36:25',
        'person_name' => 'Adham',
        'person_hikvision_id' => 'adham-person-12',
        'device_name' => 'Mobile App',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'raw_payload' => [
            'fullName' => 'Adham',
            'personCode' => '12',
            'clockInSource' => 3,
        ],
        'fetched_at' => '2026-06-20 08:51:47',
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'adham:mobile:checkout',
        'msg_type' => 'attendance/totaltimecard',
        'occurrence_time' => '2026-06-19 18:51:45',
        'person_name' => 'Adham',
        'person_hikvision_id' => 'adham-person-12',
        'device_name' => 'Mobile App',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_OUT,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ATTENDANCE_API,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_MOBILE_APP,
        'raw_payload' => [
            'fullName' => 'Adham',
            'personCode' => '12',
            'clockOutSource' => 3,
        ],
        'fetched_at' => '2026-06-20 08:51:47',
    ]);

    app(HikvisionService::class)->syncAttendanceForDay(Carbon::parse('2026-06-19'));

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-19')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_MOBILE)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and($record->clock_in?->format('H:i'))->toBe('08:36')
        ->and($record->clock_out?->format('H:i'))->toBe('18:51')
        ->and((float) $record->hours_worked)->toBe(10.26);
});

test('hikvision sync uses last check-in as clock-out when no checkout and multiple check-ins', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'maher-person-1',
        'full_name' => 'Maher H Jundi',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Maher H Jundi',
        'hikvision_person_id' => $person->id,
    ]);

    $checkInTimes = [
        '09:20:00',
        '13:33:00',
        '16:14:00',
        '16:42:00',
        '16:42:30',
        '16:42:45',
        '16:43:00',
    ];

    foreach ($checkInTimes as $index => $time) {
        HikvisionAccessEvent::query()->create([
            'system_id' => "maher:checkin:{$index}",
            'msg_type' => 'webhook/event/110013',
            'occurrence_time' => "2026-06-22 {$time}",
            'person_name' => 'Maher',
            'person_hikvision_id' => 'maher-person-1',
            'device_name' => 'OMS-Door',
            'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
            'event_source' => HikvisionAccessEvent::EVENT_SOURCE_WEBHOOK,
            'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
            'fetched_at' => now(),
        ]);
    }

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-22 00:00:00'),
        Carbon::parse('2026-06-22 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-22')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->source)->toBe(AttendanceRecord::SOURCE_BIOMETRIC)
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT)
        ->and($record->clock_in?->format('H:i'))->toBe('09:20')
        ->and($record->clock_out?->format('H:i'))->toBe('16:43')
        ->and((float) $record->hours_worked)->toBeGreaterThan(0);
});

test('hikvision sync leaves clock-out null when only one check-in and no checkout', function () {
    ['company' => $company] = makeAttendanceRecordsFixtures();

    $person = HikvisionPerson::query()->create([
        'person_id' => 'single-checkin-person-1',
        'full_name' => 'Single Checkin Employee',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Single Checkin Employee',
        'hikvision_person_id' => $person->id,
    ]);

    HikvisionAccessEvent::query()->create([
        'system_id' => 'single:checkin',
        'msg_type' => 'acs/5/38',
        'occurrence_time' => '2026-06-22 09:20:00',
        'person_name' => 'Single Checkin Employee',
        'person_hikvision_id' => 'single-checkin-person-1',
        'device_name' => 'OMS-Door',
        'attendance_status' => HikvisionAccessEvent::ATTENDANCE_CHECK_IN,
        'event_source' => HikvisionAccessEvent::EVENT_SOURCE_ACS_ISAPI,
        'transaction_source' => HikvisionAccessEvent::TRANSACTION_DEVICE,
        'fetched_at' => now(),
    ]);

    app(SyncAttendanceRecordsFromHikvision::class)->syncCompany(
        $company->id,
        Carbon::parse('2026-06-22 00:00:00'),
        Carbon::parse('2026-06-22 23:59:59'),
    );

    $record = AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-22')
        ->first();

    expect($record)->not->toBeNull()
        ->and($record->clock_in?->format('H:i'))->toBe('09:20')
        ->and($record->clock_out)->toBeNull()
        ->and($record->status)->toBe(AttendanceRecord::STATUS_PRESENT);
});

test('cannot create duplicate attendance record for same employee and date', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.create',
        'attendance.records.manage',
    ]);

    AttendanceRecord::factory()->forEmployee($employee)->create(['date' => '2026-06-12']);

    $this->withSession(['current_company_id' => $company->id])
        ->from('/attendance/records')
        ->post('/attendance/records', validAttendanceRecordPayload($employee, ['date' => '2026-06-12']))
        ->assertSessionHasErrors([
            'date' => 'An attendance record already exists for this employee on this date. Edit the existing record instead.',
        ]);

    expect(AttendanceRecord::query()
        ->where('employee_id', $employee->id)
        ->whereDate('date', '2026-06-12')
        ->count())->toBe(1);
});

test('cannot update attendance record to duplicate another day for the same employee', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.update',
        'attendance.records.manage',
    ]);

    AttendanceRecord::factory()->forEmployee($employee)->create(['date' => '2026-06-12']);
    $laterRecord = AttendanceRecord::factory()->forEmployee($employee)->create(['date' => '2026-06-13']);

    $this->withSession(['current_company_id' => $company->id])
        ->from('/attendance/records')
        ->put("/attendance/records/{$laterRecord->id}", validAttendanceRecordPayload($employee, ['date' => '2026-06-12']))
        ->assertSessionHasErrors([
            'date' => 'An attendance record already exists for this employee on this date. Edit the existing record instead.',
        ]);

    expect($laterRecord->fresh()->date?->toDateString())->toBe('2026-06-13');
});

test('users without manage permission cannot export attendance records', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.records.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records/export')
        ->assertForbidden();
});

test('users with manage permission can export filtered attendance records', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceRecordsFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'name' => 'Export Employee',
        'employee_no' => 'EXP001',
    ]);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-12',
        'clock_in' => '2026-06-12 08:00:00',
        'clock_out' => '2026-06-12 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'source' => AttendanceRecord::SOURCE_BIOMETRIC,
    ]);

    AttendanceRecord::factory()->forEmployee($employee)->create([
        'date' => '2026-06-15',
        'status' => AttendanceRecord::STATUS_ABSENT,
        'source' => null,
        'clock_in' => null,
        'clock_out' => null,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.records.view',
        'attendance.records.manage',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/records/export?date_from=2026-06-12&date_to=2026-06-12&search=Export')
        ->assertOk()
        ->assertDownload('attendance-records_2026-06-12_to_2026-06-12.xlsx');
});
