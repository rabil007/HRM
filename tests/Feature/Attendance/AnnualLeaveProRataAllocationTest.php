<?php

use App\Enums\LeaveTypeCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Reports\Actions\SyncMissingLeaveBalances;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;

test('february joiner receives pro rata annual entitlement on provisioning', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(28.0);

    Carbon::setTestNow();
});

test('excluded department employees do not receive balances on leave type provisioning', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    createAttendanceLeaveEmployee($company, ['hire_date' => '2026-02-02']);

    $excludedDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine excluded',
        'code' => 'MEX'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $excludedDepartment->id,
        'hire_date' => '2026-02-02',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    app(LeaveBalanceManager::class)->provisionLeaveType($leaveType);

    expect(
        LeaveBalance::query()
            ->where('company_id', $company->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', 2026)
            ->count()
    )->toBe(1);

    Carbon::setTestNow();
});

test('rollover applies pro rata for joiners and skips excluded departments', function () {
    Carbon::setTestNow(Carbon::parse('2026-12-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $included = createAttendanceLeaveEmployee($company, ['hire_date' => '2026-02-02']);
    $excludedDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Excluded rollover',
        'code' => 'ER'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $excluded = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $excludedDepartment->id,
        'hire_date' => '2026-02-02',
    ]);

    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'carry_forward' => false,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($included)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
        'used_days' => 0,
        'pending_days' => 0,
        'carried_days' => 0,
        'rollover_applied_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->rolloverCompany((int) $company->id, 2027);

    $included2027 = LeaveBalance::query()
        ->where('employee_id', $included->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2027)
        ->first();

    expect($included2027)->not->toBeNull()
        ->and((float) $included2027->entitled_days)->toBe(30.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $excluded->id)
                ->where('year', 2027)
                ->exists()
        )->toBeFalse();

    Carbon::setTestNow();
});

test('sync missing balances uses pro rata when department becomes included', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Later included',
        'code' => 'LI'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $department->id,
    ]);
    $employee->update(['hire_date' => '2026-02-02']);

    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $department->update(['include_in_attendance_leave' => true]);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertRedirect();

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(28.0);

    Carbon::setTestNow();
});

test('existing annual balance entitlement is not overwritten by provisioning', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, ['hire_date' => '2026-02-02']);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $existing = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 30,
        'used_days' => 4,
        'pending_days' => 2,
        'carried_days' => 1,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $after = $existing->fresh();
    expect((float) $after->entitled_days)->toBe(30.0)
        ->and((float) $after->used_days)->toBe(4.0)
        ->and((float) $after->pending_days)->toBe(2.0)
        ->and((float) $after->carried_days)->toBe(1.0);

    Carbon::setTestNow();
});

test('provision employee creates pro rata annual leave for included department', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);

    app(LeaveBalanceManager::class)->provisionEmployee($employee);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(28.0);

    Carbon::setTestNow();
});

test('sync missing balances action uses pro rata for annual category', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);
    $employee = createAttendanceLeaveEmployee($company);
    $employee->update(['hire_date' => '2026-10-01']);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', (int) now(CompanyTimezone::forCompanyId($company->id))->year)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(8.0);

    Carbon::setTestNow();
});
