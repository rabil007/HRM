<?php

use App\Enums\LeaveTypeCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\AnnualLeaveEntitlementCalculator;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveTypeYearBalance;
use App\Support\Reports\Actions\SyncMissingLeaveBalances;
use App\Support\Settings\CompanyTimezone;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

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

    Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'Asia/Dubai'));

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

test('missing hire date does not create an annual leave balance row', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);
    $employee->update(['hire_date' => null]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $sick = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Sick,
        'days_per_year' => 12,
        'status' => 'active',
    ]);

    app(LeaveBalanceManager::class)->provisionEmployee($employee);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $annual->id)
            ->where('year', 2026)
            ->exists()
    )->toBeFalse()
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $employee->id)
                ->where('leave_type_id', $sick->id)
                ->where('year', 2026)
                ->exists()
        )->toBeTrue();

    Carbon::setTestNow();
});

test('entering hire date later allows sync missing balances to provision annual leave', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $employee = createAttendanceLeaveEmployee($company);
    $employee->update(['hire_date' => null]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $annual->id)
            ->where('year', 2026)
            ->exists()
    )->toBeFalse();

    $employee->update(['hire_date' => '2026-02-02']);

    app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(28.0);

    Carbon::setTestNow();
});

test('future hire date does not interrupt leave type provisioning for other employees', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $eligible = createAttendanceLeaveEmployee($company);
    $eligible->update(['hire_date' => '2026-01-01']);

    $futureHire = createAttendanceLeaveEmployee($company);
    $futureHire->update(['hire_date' => '2027-03-01']);

    grantCompanyPermissions($user, $company, ['attendance.types.create']);
    $this->actingAs($user);

    $this->post(route('attendance.types.store'), [
        'name' => 'Annual Future Safe',
        'code' => 'AFS',
        'days_per_year' => 30,
        'carry_forward' => false,
        'max_carry_days' => 0,
        'color' => '#3b82f6',
        'status' => 'active',
        'payroll_treatment' => 'paid',
        'category' => 'annual',
    ])->assertRedirect();

    $annual = LeaveType::query()->where('company_id', $company->id)->where('code', 'AFS')->first();

    expect($annual)->not->toBeNull()
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $eligible->id)
                ->where('leave_type_id', $annual->id)
                ->where('year', 2026)
                ->value('entitled_days')
        )->toEqual(30.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $futureHire->id)
                ->where('leave_type_id', $annual->id)
                ->where('year', 2026)
                ->exists()
        )->toBeFalse();

    Carbon::setTestNow();
});

test('my leave and calendar do not show fabricated annual entitlement when allocation was skipped', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, ['user_id' => $user->id]);
    $employee->update(['hire_date' => null]);

    LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'name' => 'Annual Leave',
        'code' => 'ANN',
        'days_per_year' => 30,
        'status' => 'active',
        'color' => '#0ea5e9',
    ]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/attendance/my-leave')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_balances.0.allocation_status', 'unallocated')
            ->where(
                'leave_balances.0.allocation_message',
                AnnualLeaveEntitlementCalculator::MESSAGE_HIRE_DATE_REQUIRED,
            )
            ->where('leave_balances.0.entitled_days', 0)
            ->where('leave_balances.0.remaining_days', 0));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('attendance.calendar.index', ['year' => 2026]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_types.0.allocation_status', 'unallocated')
            ->where(
                'leave_types.0.allocation_message',
                AnnualLeaveEntitlementCalculator::MESSAGE_HIRE_DATE_REQUIRED,
            )
            ->where('leave_types.0.entitled_days', 0));

    Carbon::setTestNow();
});

test('existing annual balance is preserved when hire date changes afterward', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $existing = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 30,
        'used_days' => 3,
        'pending_days' => 1,
        'carried_days' => 0,
    ]);

    $employee->update(['hire_date' => '2026-10-01']);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $after = $existing->fresh();
    expect((float) $after->entitled_days)->toBe(30.0)
        ->and((float) $after->used_days)->toBe(3.0)
        ->and((float) $after->pending_days)->toBe(1.0);

    $legend = app(LeaveTypeYearBalance::class)->forEmployee((int) $company->id, (int) $employee->id, 2026);
    $annualLegend = collect($legend)->firstWhere('id', $annual->id);

    expect($annualLegend)->not->toBeNull()
        ->and($annualLegend['allocation_status'])->toBe('allocated')
        ->and((float) $annualLegend['base_entitlement_days'])->toBe(30.0);

    Carbon::setTestNow();
});

test('shared balance creation rejects excluded departments and cross company combinations', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    ['company' => $otherCompany] = makeLeaveBalanceFixtures();

    $excludedDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Direct excluded',
        'code' => 'DEX'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $excludedEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $excludedDepartment->id,
        'hire_date' => '2026-01-01',
    ]);

    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $otherLeaveType = LeaveType::factory()->for($otherCompany)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $manager = app(LeaveBalanceManager::class);

    expect($manager->tryFindOrCreateBalance((int) $company->id, (int) $excludedEmployee->id, $annual, 2026))
        ->toBeNull()
        ->and($manager->tryFindOrCreateBalance((int) $company->id, (int) $excludedEmployee->id, $otherLeaveType, 2026))
        ->toBeNull()
        ->and($manager->provisionMissingBalanceForYear((int) $company->id, (int) $excludedEmployee->id, $annual, 2026))
        ->toBe('skipped');

    $included = createAttendanceLeaveEmployee($company, ['hire_date' => '2026-01-01']);
    $manual = LeaveBalance::factory()->forEmployee($included)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 25,
        'used_days' => 2,
        'pending_days' => 0,
        'carried_days' => 0,
    ]);

    $included->update(['department_id' => $excludedDepartment->id]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $included->id, 2026);

    $after = $manual->fresh();
    expect((float) $after->entitled_days)->toBe(25.0)
        ->and((float) $after->used_days)->toBe(2.0);

    Carbon::setTestNow();
});

test('future same year hire date does not create annual leave until joining day', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $futureJoiner = createAttendanceLeaveEmployee($company);
    $futureJoiner->update(['hire_date' => '2026-12-31']);

    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $result = app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $futureJoiner->id)
            ->where('leave_type_id', $annual->id)
            ->where('year', 2026)
            ->exists()
    )->toBeFalse()
        ->and($result->skippedAnnualNotYetJoined)->toBe(1)
        ->and($result->skippedOrAnomalies)->toBe(0)
        ->and($result->messageVariant())->toBe('expected_skips_only');

    Carbon::setTestNow(Carbon::parse('2026-12-31 09:00:00', 'Asia/Dubai'));

    app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    $balance = LeaveBalance::query()
        ->where('employee_id', $futureJoiner->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->entitled_days)->toBe(1.0);

    Carbon::setTestNow();
});

test('sync missing balances reports skipped annual allocations for missing hire dates', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $employee = createAttendanceLeaveEmployee($company);
    $employee->update(['hire_date' => null]);

    LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    $result = app(SyncMissingLeaveBalances::class)->handle((int) $company->id, $user);

    expect($result->skippedAnnualMissingHireDate)->toBe(1)
        ->and($result->skippedOrAnomalies)->toBe(0)
        ->and($result->messageVariant())->toBe('expected_skips_only')
        ->and($result->summaryMessage())->toContain('hire dates are missing');

    Carbon::setTestNow();
});

test('inactive employee without saved balance shows unallocated instead of configured days', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $inactive = createAttendanceLeaveEmployee($company, [
        'status' => 'inactive',
        'hire_date' => '2026-01-01',
    ]);

    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $legend = app(LeaveTypeYearBalance::class)->forEmployee((int) $company->id, (int) $inactive->id, 2026);
    $annualLegend = collect($legend)->firstWhere('id', $annual->id);

    expect($annualLegend)->not->toBeNull()
        ->and($annualLegend['allocation_status'])->toBe('unallocated')
        ->and($annualLegend['allocation_skip_reason'])->toBe(LeaveTypeYearBalance::SKIP_INACTIVE_EMPLOYEE)
        ->and($annualLegend['allocation_message'])->toBe(LeaveTypeYearBalance::MESSAGE_INACTIVE_EMPLOYEE)
        ->and((float) $annualLegend['entitled_days'])->toBe(0.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $inactive->id)
                ->where('leave_type_id', $annual->id)
                ->where('year', 2026)
                ->exists()
        )->toBeFalse();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('attendance.calendar.index', [
            'year' => 2026,
            'employee_id' => $inactive->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('leave_types.0.allocation_status', 'unallocated')
            ->where('leave_types.0.entitled_days', 0));

    Carbon::setTestNow();
});

test('inactive employee with existing balance shows persisted entitlement', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeLeaveBalanceFixtures();
    $inactive = createAttendanceLeaveEmployee($company, [
        'status' => 'inactive',
        'hire_date' => '2026-01-01',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($inactive)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 18,
        'used_days' => 4,
        'pending_days' => 1,
        'carried_days' => 2,
    ]);

    $legend = app(LeaveTypeYearBalance::class)->forEmployee((int) $company->id, (int) $inactive->id, 2026);
    $annualLegend = collect($legend)->firstWhere('id', $annual->id);

    expect($annualLegend)->not->toBeNull()
        ->and($annualLegend['allocation_status'])->toBe('allocated')
        ->and((float) $annualLegend['base_entitlement_days'])->toBe(18.0)
        ->and((float) $annualLegend['entitled_days'])->toBe(20.0)
        ->and((float) $annualLegend['used_days'])->toBe(4.0)
        ->and((float) $annualLegend['pending_days'])->toBe(1.0);

    Carbon::setTestNow();
});
