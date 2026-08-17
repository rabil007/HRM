<?php

use App\Enums\PlatformAccess;
use App\Models\AttendanceRecord;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{actor: User, companyA: Company, companyB: Company}
 */
function makeAttendanceWriteAuthCompanies(): array
{
    $suffix = Str::lower(Str::random(6));

    $country = Country::query()->create([
        'code' => strtoupper(substr($suffix, 0, 3)),
        'name' => 'Attendance Write Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => strtoupper(substr($suffix, 3, 3)),
        'name' => 'Attendance Write Currency '.$suffix,
        'symbol' => 'W$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Alpha Attendance '.$suffix,
        'slug' => 'alpha-attendance-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Beta Attendance '.$suffix,
        'slug' => 'beta-attendance-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $actor = User::factory()->create(['company_id' => $companyA->id]);

    DB::table('company_user')->insert([
        'company_id' => $companyA->id,
        'user_id' => $actor->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('actor', 'companyA', 'companyB');
}

/**
 * @return array<string, mixed>
 */
function attendanceWritePayload(Employee $employee, array $overrides = []): array
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

test('self-service user can create attendance for their linked employee', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    $own = Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    grantCompanyPermissions($actor, $companyA, ['attendance.records.view', 'attendance.records.create']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($own, [
            'company_id' => $companyB->id,
        ]))
        ->assertRedirect();

    $record = AttendanceRecord::query()->where('employee_id', $own->id)->first();

    expect($record)->not->toBeNull()
        ->and((int) $record->company_id)->toBe($companyA->id);
});

test('self-service user cannot create attendance for a same-company coworker', function () {
    ['actor' => $actor, 'companyA' => $companyA] = makeAttendanceWriteAuthCompanies();
    Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    $coworker = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    grantCompanyPermissions($actor, $companyA, ['attendance.records.view', 'attendance.records.create']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($coworker))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($coworker, [
            'date' => 'not-a-date',
            'status' => 'invalid',
        ]))
        ->assertNotFound();

    expect(AttendanceRecord::query()->where('employee_id', $coworker->id)->exists())->toBeFalse();
});

test('self-service user cannot create attendance for a cross-company employee', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    $foreign = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);
    grantCompanyPermissions($actor, $companyA, ['attendance.records.view', 'attendance.records.create']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($foreign))
        ->assertNotFound();
});

test('self-service user without a linked active-company employee cannot create attendance', function () {
    ['actor' => $actor, 'companyA' => $companyA] = makeAttendanceWriteAuthCompanies();
    $unlinked = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    grantCompanyPermissions($actor, $companyA, ['attendance.records.view', 'attendance.records.create']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($unlinked))
        ->assertNotFound();
});

test('manager can create attendance for another same-company employee but not another company', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    $coworker = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $foreign = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);
    grantCompanyPermissions($actor, $companyA, [
        'attendance.records.view',
        'attendance.records.create',
        'attendance.records.manage',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($coworker, ['date' => '2026-06-11']))
        ->assertRedirect();

    expect(AttendanceRecord::query()->where('employee_id', $coworker->id)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($foreign, ['date' => '2026-06-12']))
        ->assertNotFound();
});

test('self-service user can update own record but cannot reassign it or edit a coworker record', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    $own = Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    $coworker = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $ownRecord = AttendanceRecord::factory()->forEmployee($own)->create(['date' => '2026-06-10']);
    $coworkerRecord = AttendanceRecord::factory()->forEmployee($coworker)->create(['date' => '2026-06-10']);
    $foreign = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);
    $foreignRecord = AttendanceRecord::factory()->forEmployee($foreign)->create(['date' => '2026-06-10']);

    grantCompanyPermissions($actor, $companyA, [
        'attendance.records.view',
        'attendance.records.update',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$ownRecord->id}", attendanceWritePayload($own, [
            'notes' => 'Updated own notes',
        ]))
        ->assertRedirect();

    expect($ownRecord->fresh()->notes)->toBe('Updated own notes');

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$ownRecord->id}", attendanceWritePayload($coworker))
        ->assertNotFound();

    expect((int) $ownRecord->fresh()->employee_id)->toBe($own->id);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$coworkerRecord->id}", attendanceWritePayload($coworker, [
            'date' => 'not-a-date',
            'status' => 'invalid',
        ]))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$foreignRecord->id}", attendanceWritePayload($foreign))
        ->assertNotFound();
});

test('manager can update a same-company record and cannot retarget it to another company', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    $employee = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $other = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $foreign = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);
    $record = AttendanceRecord::factory()->forEmployee($employee)->create(['date' => '2026-06-10']);

    grantCompanyPermissions($actor, $companyA, [
        'attendance.records.view',
        'attendance.records.update',
        'attendance.records.manage',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$record->id}", attendanceWritePayload($other, [
            'notes' => 'Reassigned in company',
        ]))
        ->assertRedirect();

    expect((int) $record->fresh()->employee_id)->toBe($other->id);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/attendance/records/{$record->id}", attendanceWritePayload($foreign))
        ->assertNotFound();

    expect((int) $record->fresh()->employee_id)->toBe($other->id);
});

test('self-service destroy is limited to the linked employee record', function () {
    ['actor' => $actor, 'companyA' => $companyA] = makeAttendanceWriteAuthCompanies();
    $own = Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    $coworker = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $ownRecord = AttendanceRecord::factory()->forEmployee($own)->create(['date' => '2026-06-10']);
    $coworkerRecord = AttendanceRecord::factory()->forEmployee($coworker)->create(['date' => '2026-06-10']);

    grantCompanyPermissions($actor, $companyA, [
        'attendance.records.view',
        'attendance.records.delete',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/attendance/records/{$coworkerRecord->id}")
        ->assertNotFound();

    expect(AttendanceRecord::query()->whereKey($coworkerRecord->id)->exists())->toBeTrue();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/attendance/records/{$ownRecord->id}")
        ->assertRedirect();

    expect(AttendanceRecord::query()->find($ownRecord->id))->toBeNull();
});

test('dual-company user cannot write attendance for company B while active in A', function () {
    ['actor' => $actor, 'companyA' => $companyA, 'companyB' => $companyB] = makeAttendanceWriteAuthCompanies();
    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active', 'user_id' => $actor->id]);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active', 'user_id' => $actor->id]);

    grantCompanyPermissions($actor, $companyA, ['attendance.records.view', 'attendance.records.create']);
    grantCompanyPermissions($actor, $companyB, ['attendance.records.view', 'attendance.records.create']);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/attendance/records', attendanceWritePayload($employeeB))
        ->assertNotFound();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/organization/companies/switch', ['company_id' => $companyB->id])
        ->assertRedirect();

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $companyB->id])
        ->post('/attendance/records', attendanceWritePayload($employeeB, ['date' => '2026-06-13']))
        ->assertRedirect();

    expect(AttendanceRecord::query()->where('employee_id', $employeeB->id)->exists())->toBeTrue()
        ->and(AttendanceRecord::query()->where('employee_id', $employeeA->id)->exists())->toBeFalse();
});

test('platform access without attendance permission cannot create records', function () {
    ['companyA' => $companyA] = makeAttendanceWriteAuthCompanies();
    $employee = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $platformUser = User::factory()->create(['company_id' => null]);
    $platformUser->forceFill(['platform_access' => PlatformAccess::Manage])->save();

    $this->actingAs($platformUser)
        ->withSession([])
        ->post('/attendance/records', attendanceWritePayload($employee))
        ->assertForbidden();
});
