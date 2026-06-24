<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveBalanceFixtures(): array
{
    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'LB'.fake()->unique()->numerify('##'),
        'name' => 'Leave Balanceland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LB'.fake()->unique()->numerify('##'),
        'name' => 'Leave Balance Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Balance Co',
        'slug' => 'balance-'.fake()->unique()->numerify('####'),
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

test('leave balance rollover carries unused days up to max carry days', function () {
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'status' => 'active',
    ]);

    LeaveBalance::factory()
        ->forEmployee($employee)
        ->forLeaveType($leaveType)
        ->create([
            'year' => 2025,
            'entitled_days' => 30,
            'carried_days' => 0,
            'used_days' => 22,
            'pending_days' => 0,
        ]);

    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2026);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(30.0)
        ->and((float) $balance->carried_days)->toBe(8.0)
        ->and((float) $balance->remaining_days)->toBe(38.0);
});

test('leave balance rollover ignores carry forward when disabled on leave type', function () {
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 15,
        'carry_forward' => false,
        'max_carry_days' => 10,
        'status' => 'active',
    ]);

    LeaveBalance::factory()
        ->forEmployee($employee)
        ->forLeaveType($leaveType)
        ->create([
            'year' => 2025,
            'entitled_days' => 15,
            'used_days' => 5,
            'pending_days' => 0,
            'carried_days' => 0,
        ]);

    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2026);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->carried_days)->toBe(0.0)
        ->and((float) $balance->remaining_days)->toBe(15.0);
});

test('sync command rebuilds used and pending days from leave requests', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

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

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->artisan('leave-balances:sync', ['year' => 2026])->assertSuccessful();

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->used_days)->toBe(3.0)
        ->and((float) $balance->pending_days)->toBe(2.0)
        ->and((float) $balance->remaining_days)->toBe(25.0);
});

test('first leave request succeeds when employee has no pre-provisioned balance', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    expect(LeaveBalance::query()->where('employee_id', $employee->id)->count())->toBe(0);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-21',
        'end_date' => '2026-06-23',
        'reason' => 'First request',
    ])->assertRedirect(route('attendance.leave-requests.index'));

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->pending_days)->toBe(3.0)
        ->and((float) $balance->remaining_days)->toBe(27.0);
});

test('leave requests cannot exceed available balance', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 2,
        'status' => 'active',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->post('/attendance/leave-requests', [
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'reason' => 'Too many days',
    ])->assertSessionHasErrors('leave_type_id');
});

test('creating leave type provisions balances for active employees', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    Employee::factory()->forCompany($company)->create(['status' => 'active']);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.types.create']);

    $this->post(route('attendance.types.store'), [
        'name' => 'Annual Leave',
        'code' => 'AL',
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 5,
        'color' => '#3b82f6',
        'status' => 'active',
    ])->assertRedirect();

    $leaveType = LeaveType::query()->where('company_id', $company->id)->where('code', 'AL')->first();

    expect($leaveType)->not->toBeNull()
        ->and(
            LeaveBalance::query()
                ->where('company_id', $company->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', (int) now()->year)
                ->count(),
        )->toBe(2);
});
