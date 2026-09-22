<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\LeaveBalance;
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
    $employee = createAttendanceLeaveEmployee($company);
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
    $employee = createAttendanceLeaveEmployee($company);
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
    $employee = createAttendanceLeaveEmployee($company);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    createLeaveRequestRecord([
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

    createLeaveRequestRecord([
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
    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'user_id' => $user->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    expect(LeaveBalance::query()->where('employee_id', $employee->id)->count())->toBe(0);

    prepareLeaveRequestApprovalContext($company, $employee);

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
    ])->assertRedirect(route('attendance.my-leave.index'));

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
    $employee = createAttendanceLeaveEmployee($company, [
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
    createAttendanceLeaveEmployee($company);
    createAttendanceLeaveEmployee($company);

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
        'payroll_treatment' => 'paid',
        'category' => 'annual',
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

test('future-year provisional balance receives carry on rollover without losing pending', function () {
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);
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
            'year' => 2026,
            'entitled_days' => 30,
            'carried_days' => 0,
            'used_days' => 22,
            'pending_days' => 0,
            'rollover_applied_at' => now(),
        ]);

    // Provisional 2027 row from a future leave reservation (carry not yet applied).
    LeaveBalance::factory()
        ->forEmployee($employee)
        ->forLeaveType($leaveType)
        ->create([
            'year' => 2027,
            'entitled_days' => 30,
            'carried_days' => 0,
            'used_days' => 0,
            'pending_days' => 0,
            'rollover_applied_at' => null,
        ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2027-01-05',
        'end_date' => '2027-01-09',
        'total_days' => 5,
        'status' => 'pending',
    ]);

    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2027);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2027)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(30.0)
        ->and((float) $balance->carried_days)->toBe(8.0)
        ->and((float) $balance->pending_days)->toBe(5.0)
        ->and((float) $balance->remaining_days)->toBe(33.0)
        ->and($balance->rollover_applied_at)->not->toBeNull();

    // Idempotent second run
    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2027);

    $balance->refresh();
    expect((float) $balance->carried_days)->toBe(8.0)
        ->and((float) $balance->pending_days)->toBe(5.0)
        ->and((float) $balance->remaining_days)->toBe(33.0);
});

test('rollover respects max carry cap and disabled carry forward', function () {
    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);
    $capped = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 5,
        'status' => 'active',
    ]);
    $noCarry = LeaveType::factory()->for($company)->create([
        'days_per_year' => 20,
        'carry_forward' => false,
        'max_carry_days' => 10,
        'status' => 'active',
    ]);

    foreach ([$capped, $noCarry] as $type) {
        LeaveBalance::factory()
            ->forEmployee($employee)
            ->forLeaveType($type)
            ->create([
                'year' => 2026,
                'entitled_days' => $type->days_per_year,
                'carried_days' => 0,
                'used_days' => 10,
                'pending_days' => 0,
                'rollover_applied_at' => now(),
            ]);
    }

    app(LeaveBalanceManager::class)->rolloverCompany($company->id, 2027);

    $cappedBalance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $capped->id)
        ->where('year', 2027)
        ->first();
    $noCarryBalance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $noCarry->id)
        ->where('year', 2027)
        ->first();

    expect((float) $cappedBalance->carried_days)->toBe(5.0)
        ->and((float) $noCarryBalance->carried_days)->toBe(0.0);
});

test('sync repairs multi-year leave allocation and inactive leave type balances', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);
    $activeType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $inactiveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 10,
        'status' => 'inactive',
        'code' => 'EMG',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $activeType->id,
        'start_date' => '2026-12-30',
        'end_date' => '2027-01-03',
        'total_days' => 5,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $inactiveType->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-02',
        'total_days' => 2,
        'status' => 'approved',
        'approved_by' => $user->id,
        'decided_at' => now(),
    ]);

    // Corrupt / missing usage on existing inactive-type balance.
    LeaveBalance::factory()
        ->forEmployee($employee)
        ->forLeaveType($inactiveType)
        ->create([
            'year' => 2026,
            'entitled_days' => 10,
            'used_days' => 0,
            'pending_days' => 0,
            'carried_days' => 0,
        ]);

    $this->artisan('leave-balances:sync')->assertSuccessful();

    $y2026 = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $activeType->id)
        ->where('year', 2026)
        ->first();
    $y2027 = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $activeType->id)
        ->where('year', 2027)
        ->first();
    $inactiveBalance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $inactiveType->id)
        ->where('year', 2026)
        ->first();

    expect($y2026)->not->toBeNull()
        ->and((float) $y2026->used_days)->toBe(2.0)
        ->and($y2027)->not->toBeNull()
        ->and((float) $y2027->used_days)->toBe(3.0)
        ->and($inactiveBalance)->not->toBeNull()
        ->and((float) $inactiveBalance->used_days)->toBe(2.0)
        ->and((float) $inactiveBalance->entitled_days)->toBe(10.0);
});

test('rollover remains company scoped', function () {
    ['company' => $companyA] = makeLeaveBalanceFixtures();
    ['company' => $companyB] = makeLeaveBalanceFixtures();
    $employeeA = createAttendanceLeaveEmployee($companyA);
    $employeeB = createAttendanceLeaveEmployee($companyB, ['status' => 'active']);
    $typeA = LeaveType::factory()->for($companyA)->create([
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'status' => 'active',
    ]);
    $typeB = LeaveType::factory()->for($companyB)->create([
        'days_per_year' => 30,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($employeeA)->forLeaveType($typeA)->create([
        'year' => 2026,
        'entitled_days' => 30,
        'used_days' => 20,
        'pending_days' => 0,
        'carried_days' => 0,
        'rollover_applied_at' => now(),
    ]);
    LeaveBalance::factory()->forEmployee($employeeB)->forLeaveType($typeB)->create([
        'year' => 2026,
        'entitled_days' => 30,
        'used_days' => 20,
        'pending_days' => 0,
        'carried_days' => 0,
        'rollover_applied_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->rolloverCompany($companyA->id, 2027);

    expect(
        LeaveBalance::query()
            ->where('company_id', $companyA->id)
            ->where('year', 2027)
            ->exists()
    )->toBeTrue()
        ->and(
            LeaveBalance::query()
                ->where('company_id', $companyB->id)
                ->where('year', 2027)
                ->exists()
        )->toBeFalse();
});

test('automatic rollover uses each company business year near the UTC boundary', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2027-01-01 00:30:00', 'UTC'));

    $dubai = makeLeaveBalanceFixtures()['company'];
    $dubai->forceFill(['timezone' => 'Asia/Dubai'])->save();

    $newYorkCountry = Country::query()->create([
        'code' => 'NY'.fake()->unique()->numerify('##'),
        'name' => 'NY Land',
        'dial_code' => '+1',
        'is_active' => true,
    ]);
    $newYorkCurrency = Currency::query()->create([
        'code' => 'NY'.fake()->unique()->numerify('##'),
        'name' => 'NY Currency',
        'symbol' => '$',
        'is_active' => true,
    ]);
    $newYork = Company::query()->create([
        'name' => 'NY Co',
        'slug' => 'ny-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $newYorkCountry->id,
        'currency_id' => $newYorkCurrency->id,
        'timezone' => 'America/New_York',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    foreach ([$dubai, $newYork] as $company) {
        $employee = createAttendanceLeaveEmployee($company);
        $leaveType = LeaveType::factory()->for($company)->create([
            'days_per_year' => 30,
            'carry_forward' => true,
            'max_carry_days' => 10,
            'status' => 'active',
        ]);
        LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
            'year' => 2026,
            'entitled_days' => 30,
            'carried_days' => 0,
            'used_days' => 20,
            'pending_days' => 0,
            'rollover_applied_at' => now(),
        ]);
    }

    $this->artisan('leave-balances:rollover')->assertSuccessful();

    // Dubai is already 04:30 on 1 Jan 2027 → opens 2027.
    expect(
        LeaveBalance::query()->where('company_id', $dubai->id)->where('year', 2027)->exists()
    )->toBeTrue();

    // New York is still 19:30 on 31 Dec 2026 → must not open 2027 yet.
    expect(
        LeaveBalance::query()->where('company_id', $newYork->id)->where('year', 2027)->exists()
    )->toBeFalse();

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2027-01-01 05:30:00', 'UTC'));
    $this->artisan('leave-balances:rollover')->assertSuccessful();

    expect(
        LeaveBalance::query()->where('company_id', $newYork->id)->where('year', 2027)->exists()
    )->toBeTrue();

    Carbon\Carbon::setTestNow();
});

test('corrective rollover backfill marks current company year as opened without changing carry', function () {
    $company = makeLeaveBalanceFixtures()['company'];
    $company->forceFill(['timezone' => 'Asia/Dubai'])->save();
    $employee = createAttendanceLeaveEmployee($company);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    $current = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => 2026,
        'entitled_days' => 30,
        'carried_days' => 4,
        'used_days' => 1,
        'pending_days' => 0,
        'rollover_applied_at' => null,
    ]);
    $future = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => 2027,
        'entitled_days' => 30,
        'carried_days' => 0,
        'used_days' => 0,
        'pending_days' => 2,
        'rollover_applied_at' => null,
    ]);

    $migration = require base_path('database/migrations/2026_09_21_170000_correct_leave_balances_rollover_applied_at_by_company_year.php');
    $migration->up();

    $current->refresh();
    $future->refresh();

    expect($current->rollover_applied_at)->not->toBeNull()
        ->and((float) $current->carried_days)->toBe(4.0)
        ->and($future->rollover_applied_at)->toBeNull()
        ->and((float) $future->pending_days)->toBe(2.0);

    Carbon\Carbon::setTestNow();
});

test('sync repairs inactive employee historical balances without inventing missing historical entitlement', function () {
    ['company' => $company] = makeLeaveBalanceFixtures();
    $active = createAttendanceLeaveEmployee($company);
    $inactive = createAttendanceLeaveEmployee($company, ['status' => 'inactive']);
    $irrelevant = createAttendanceLeaveEmployee($company, ['status' => 'terminated']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($inactive)->forLeaveType($leaveType)->create([
        'year' => 2023,
        'entitled_days' => 25,
        'carried_days' => 2,
        'used_days' => 0,
        'pending_days' => 0,
        'rollover_applied_at' => now(),
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactive->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2023-03-01',
        'end_date' => '2023-03-03',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    // Historical approved leave with no balance — must not invent 2024 entitlement from today's 30.
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactive->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2024-04-01',
        'end_date' => '2024-04-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $beforeCount = LeaveBalance::query()->where('company_id', $company->id)->count();

    app(LeaveBalanceManager::class)->syncCompany((int) $company->id, 2023);
    app(LeaveBalanceManager::class)->syncCompany((int) $company->id, 2024);

    $inactive2023 = LeaveBalance::query()
        ->where('employee_id', $inactive->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2023)
        ->first();

    expect($inactive2023)->not->toBeNull()
        ->and((float) $inactive2023->used_days)->toBe(3.0)
        ->and((float) $inactive2023->entitled_days)->toBe(25.0)
        ->and((float) $inactive2023->carried_days)->toBe(2.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $inactive->id)
                ->where('year', 2024)
                ->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $irrelevant->id)
                ->exists()
        )->toBeFalse();

    // Current-year provisioning for active employees still works.
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $active->id, 2026);
    expect(
        LeaveBalance::query()
            ->where('employee_id', $active->id)
            ->where('year', 2026)
            ->exists()
    )->toBeTrue()
        ->and(LeaveBalance::query()->where('company_id', $company->id)->where('year', 2024)->count())
        ->toBe(0);

    expect(LeaveBalance::query()->where('company_id', $company->id)->count())
        ->toBeGreaterThanOrEqual($beforeCount);
});

test('leave-balances sync command reports historical anomalies and still succeeds', function () {
    Carbon\Carbon::setTestNow(Carbon\Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $otherCompany = Company::query()->create([
        'name' => 'Other Sync Co',
        'slug' => 'other-sync-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $active = createAttendanceLeaveEmployee($company);
    $inactive = createAttendanceLeaveEmployee($company, ['status' => 'inactive']);
    $otherEmployee = createAttendanceLeaveEmployee($otherCompany, ['status' => 'inactive']);
    $leaveType = LeaveType::factory()->for($company)->create([
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $otherType = LeaveType::factory()->for($otherCompany)->create([
        'days_per_year' => 20,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($inactive)->forLeaveType($leaveType)->create([
        'year' => 2023,
        'entitled_days' => 25,
        'carried_days' => 1,
        'used_days' => 0,
        'pending_days' => 0,
        'rollover_applied_at' => now(),
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactive->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2023-05-01',
        'end_date' => '2023-05-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    // Missing historical balance for another year — must be reported, not invented.
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $inactive->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2024-06-01',
        'end_date' => '2024-06-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $otherCompany->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $otherType->id,
        'start_date' => '2024-07-01',
        'end_date' => '2024-07-02',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $this->artisan('leave-balances:sync')
        ->expectsOutputToContain('Synced')
        ->expectsOutputToContain('WARNING:')
        ->expectsOutputToContain("Company {$company->id} / Employee {$inactive->id} / Leave Type {$leaveType->id} / 2024")
        ->assertSuccessful();

    expect(
        LeaveBalance::query()
            ->where('employee_id', $inactive->id)
            ->where('year', 2024)
            ->exists()
    )->toBeFalse()
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $inactive->id)
            ->where('year', 2023)
            ->value('used_days'))->toBe(2.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $otherEmployee->id)
                ->where('year', 2024)
                ->exists()
        )->toBeFalse();

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $active->id, 2026);
    expect(
        LeaveBalance::query()
            ->where('employee_id', $active->id)
            ->where('year', 2026)
            ->exists()
    )->toBeTrue();

    Carbon\Carbon::setTestNow();
});
