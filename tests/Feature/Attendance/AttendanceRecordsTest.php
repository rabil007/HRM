<?php

use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionPerson;
use App\Models\User;
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
