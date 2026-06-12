<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makeAttendanceTypesFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'AT'.fake()->unique()->numerify('##'),
        'name' => 'Attendance Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AT'.fake()->unique()->numerify('##'),
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
function validLeaveTypePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Annual Leave',
        'code' => 'AL',
        'days_per_year' => 30,
        'accrual_method' => 'upfront',
        'carry_forward' => false,
        'max_carry_days' => 0,
        'color' => '#3b82f6',
        'status' => 'active',
    ], $overrides);
}

test('guests cannot access attendance types page', function () {
    $this->get('/attendance/types')->assertRedirect(route('login'));
});

test('authorized users can view create update and delete attendance types', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceTypesFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.types.view',
        'attendance.types.create',
        'attendance.types.update',
        'attendance.types.delete',
    ]);

    $this->get('/attendance/types')->assertOk();

    $this->post('/attendance/types', validLeaveTypePayload())
        ->assertRedirect(route('attendance.types.index'));

    $leaveType = LeaveType::query()->where('company_id', $company->id)->where('code', 'AL')->first();
    expect($leaveType)->not->toBeNull();

    $this->put("/attendance/types/{$leaveType->id}", validLeaveTypePayload([
        'name' => 'Sick Leave',
        'code' => 'SL',
        'days_per_year' => 15,
    ]))->assertRedirect(route('attendance.types.index'));

    $this->assertDatabaseHas('leave_types', [
        'id' => $leaveType->id,
        'company_id' => $company->id,
        'name' => 'Sick Leave',
        'code' => 'SL',
    ]);

    $this->delete("/attendance/types/{$leaveType->id}")
        ->assertRedirect(route('attendance.types.index'));

    $this->assertDatabaseMissing('leave_types', ['id' => $leaveType->id]);
});

test('leave type code must be unique per company', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceTypesFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'attendance.types.create',
    ]);

    LeaveType::factory()->for($company)->create([
        'code' => 'AL',
    ]);

    $this->from('/attendance/types')
        ->post('/attendance/types', validLeaveTypePayload(['code' => 'AL']))
        ->assertSessionHasErrors('code');
});

test('users cannot update leave types from another company', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceTypesFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $leaveType = LeaveType::factory()->for($otherCompany)->create([
        'code' => 'OL',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.types.update']);

    $this->put("/attendance/types/{$leaveType->id}", validLeaveTypePayload([
        'code' => 'OL',
        'name' => 'Hacked',
    ]))->assertNotFound();
});

test('delete is blocked when leave type is used in leave balances', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceTypesFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.types.delete']);

    $leaveType = LeaveType::factory()->for($company)->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    DB::table('leave_balances')->insert([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2026,
        'entitled_days' => 30,
        'used_days' => 0,
        'pending_days' => 0,
        'carried_days' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->from('/attendance/types')
        ->delete("/attendance/types/{$leaveType->id}")
        ->assertRedirect(route('attendance.types.index'))
        ->assertSessionHasErrors('leave_type');

    $this->assertDatabaseHas('leave_types', ['id' => $leaveType->id]);
});

test('delete is blocked when leave type is used in leave requests', function () {
    ['user' => $user, 'company' => $company] = makeAttendanceTypesFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['attendance.types.delete']);

    $leaveType = LeaveType::factory()->for($company)->create();
    $employee = Employee::factory()->create(['company_id' => $company->id]);

    DB::table('leave_requests')->insert([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'total_days' => 2,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->from('/attendance/types')
        ->delete("/attendance/types/{$leaveType->id}")
        ->assertRedirect(route('attendance.types.index'))
        ->assertSessionHasErrors('leave_type');

    $this->assertDatabaseHas('leave_types', ['id' => $leaveType->id]);
});
