<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Reports\Actions\SyncMissingLeaveBalances;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

test('sync missing balances requires view and sync permissions', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view']);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertForbidden();
});

test('sync creates current year balances for eligible new employees only', function () {
    ['user' => $user, 'company' => $company, 'employee' => $existing, 'leaveType' => $annual] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $annual->update(['days_per_year' => 28, 'status' => 'active']);
    $inactiveType = LeaveType::factory()->for($company)->create([
        'status' => 'inactive',
        'days_per_year' => 10,
    ]);
    $deletedType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    $deletedType->delete();

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    $existingBalance = LeaveBalance::factory()->forEmployee($existing)->forLeaveType($annual)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'used_days' => 5,
        'pending_days' => 2,
        'carried_days' => 3,
    ]);

    $newHire = createAttendanceLeaveEmployee($company, [
        'name' => 'Missing Balances Hire',
        'employee_no' => 'NEW-SYNC',
    ]);
    $inactive = createAttendanceLeaveEmployee($company, ['status' => 'inactive']);
    $terminated = createAttendanceLeaveEmployee($company, ['status' => 'terminated']);

    $excludedDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Excluded Ops',
        'code' => 'EXCL',
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $excludedEmployee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $excludedDept->id,
    ]);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index', ['year' => $businessYear]))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertRedirect(route('organization.reports.leave-balances.index', ['year' => $businessYear]))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['new_balance_records_created'] === 1
            && $result['employees_with_new_balances'] === 1
            && $result['eligible_employees_checked'] === 2
            && $result['already_existing_balances'] === 1
            && $result['skipped_or_anomalies'] === 0);

    $annualBalance = LeaveBalance::query()
        ->where('employee_id', $newHire->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', $businessYear)
        ->first();

    expect($annualBalance)->not->toBeNull()
        ->and((float) $annualBalance->entitled_days)->toBe(28.0)
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $newHire->id)
                ->where('leave_type_id', $inactiveType->id)
                ->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $newHire->id)
                ->where('leave_type_id', $deletedType->id)
                ->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()->where('employee_id', $inactive->id)->where('year', $businessYear)->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()->where('employee_id', $terminated->id)->where('year', $businessYear)->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()->where('employee_id', $excludedEmployee->id)->where('year', $businessYear)->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()
                ->where('employee_id', $existing->id)
                ->where('leave_type_id', $annual->id)
                ->where('year', $businessYear)
                ->count()
        )->toBe(1);

    $afterExisting = $existingBalance->fresh();
    expect((float) $afterExisting->entitled_days)->toBe(30.0)
        ->and((float) $afterExisting->used_days)->toBe(5.0)
        ->and((float) $afterExisting->pending_days)->toBe(2.0)
        ->and((float) $afterExisting->carried_days)->toBe(3.0);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $newHire->id)
            ->where('year', $businessYear - 1)
            ->exists()
    )->toBeFalse();
});

test('repeated sync is idempotent and does not create duplicate balances', function () {
    ['user' => $user, 'company' => $company, 'employee' => $fixtureEmployee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    LeaveBalance::factory()->forEmployee($fixtureEmployee)->forLeaveType($leaveType)->create(['year' => $year]);

    $employee = createAttendanceLeaveEmployee($company);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result.new_balance_records_created', 1);

    $countAfterFirst = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('year', $year)
        ->count();

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['new_balance_records_created'] === 0
            && $result['message_variant'] === 'nothing_missing');

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->count()
    )->toBe($countAfterFirst);
});

test('sync respects employee visibility and tenant isolation', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    $marine = createAttendanceLeaveEmployee($company, ['department_id' => $marineDept->id]);

    $other = Company::query()->create([
        'name' => 'Foreign Sync Co',
        'slug' => 'foreign-sync-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreign = createAttendanceLeaveEmployee($other);
    LeaveType::factory()->for($other)->create(['status' => 'active']);

    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertRedirect();

    expect(
        LeaveBalance::query()->where('employee_id', $marine->id)->where('year', $year)->exists()
    )->toBeTrue()
        ->and(
            LeaveBalance::query()->where('employee_id', $office->id)->where('year', $year)->exists()
        )->toBeFalse()
        ->and(
            LeaveBalance::query()->where('employee_id', $foreign->id)->exists()
        )->toBeFalse();
});

test('sync initializes used and pending days from existing leave requests', function () {
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $employee = createAttendanceLeaveEmployee($company);
    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-03-01",
        'end_date' => "{$year}-03-03",
        'total_days' => 3,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-04-10",
        'end_date' => "{$year}-04-11",
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertRedirect();

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', $year)
        ->first();

    expect($balance)->not->toBeNull()
        ->and((float) $balance->used_days)->toBe(3.0)
        ->and((float) $balance->pending_days)->toBe(2.0);
});

test('soft deleted current year balance is skipped as anomaly and not recreated', function () {
    ['user' => $user, 'company' => $company, 'employee' => $fixtureEmployee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    LeaveBalance::factory()->forEmployee($fixtureEmployee)->forLeaveType($leaveType)->create(['year' => $year]);

    $employee = createAttendanceLeaveEmployee($company);

    $deleted = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $year,
        'entitled_days' => 20,
    ]);
    $deleted->delete();

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['skipped_or_anomalies'] === 1
            && $result['message_variant'] === 'anomalies_only'
            && $result['new_balance_records_created'] === 0);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->exists()
    )->toBeFalse()
        ->and(LeaveBalance::onlyTrashed()->whereKey($deleted->id)->exists())->toBeTrue();
});

test('repeated sync in one transaction remains idempotent without duplicate rows', function () {
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $employee = createAttendanceLeaveEmployee($company);
    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $action = app(SyncMissingLeaveBalances::class);

    DB::transaction(function () use ($action, $company, $user): void {
        $action->handle((int) $company->id, $user);
        $action->handle((int) $company->id, $user);
    });

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->count()
    )->toBe(1);
});

test('provision missing balance classifies concurrent insert as existing without reconciling', function () {
    ['company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $employee = createAttendanceLeaveEmployee($company);
    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $manager = app(LeaveBalanceManager::class);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-05-01",
        'end_date' => "{$year}-05-03",
        'total_days' => 3,
        'status' => 'approved',
    ]);

    $preexisting = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $year,
        'entitled_days' => 30,
        'used_days' => 0,
        'pending_days' => 0,
    ]);

    expect($manager->provisionMissingBalanceForYear(
        (int) $company->id,
        (int) $employee->id,
        $leaveType,
        $year,
    ))->toBe('existing')
        ->and((float) $preexisting->fresh()->used_days)->toBe(0.0);

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->count()
    )->toBe(1);
});

test('provision missing balance returns created then existing on second call', function () {
    ['company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $employee = createAttendanceLeaveEmployee($company);
    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $manager = app(LeaveBalanceManager::class);

    expect($manager->provisionMissingBalanceForYear(
        (int) $company->id,
        (int) $employee->id,
        $leaveType,
        $year,
    ))->toBe('created')
        ->and($manager->provisionMissingBalanceForYear(
            (int) $company->id,
            (int) $employee->id,
            $leaveType,
            $year,
        ))->toBe('existing');
});

test('provision missing balance rolls back when usage reconciliation fails', function () {
    ['company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $employee = createAttendanceLeaveEmployee($company);
    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $manager = app(LeaveBalanceManager::class);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$year}-06-01",
        'end_date' => "{$year}-06-02",
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $failed = false;
    LeaveBalance::updated(function () use (&$failed): void {
        if ($failed) {
            return;
        }

        $failed = true;
        throw new RuntimeException('Simulated reconciliation failure');
    });

    try {
        $manager->provisionMissingBalanceForYear(
            (int) $company->id,
            (int) $employee->id,
            $leaveType,
            $year,
        );
    } catch (RuntimeException) {
        // expected
    } finally {
        LeaveBalance::flushEventListeners();
    }

    expect(
        LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $leaveType->id)
            ->where('year', $year)
            ->exists()
    )->toBeFalse();
});

test('sync reports anomalies only when nothing was created', function () {
    ['user' => $user, 'company' => $company, 'employee' => $fixtureEmployee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $year = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    LeaveBalance::factory()->forEmployee($fixtureEmployee)->forLeaveType($leaveType)->create(['year' => $year]);

    $employee = createAttendanceLeaveEmployee($company);
    $deleted = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create(['year' => $year]);
    $deleted->delete();

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['message_variant'] === 'anomalies_only'
            && $result['new_balance_records_created'] === 0
            && $result['skipped_or_anomalies'] === 1
            && $result['already_existing_balances'] === 1);
});

test('sync reports no active leave types accurately', function () {
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $leaveType->update(['status' => 'inactive']);
    createAttendanceLeaveEmployee($company);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['message_variant'] === 'no_active_leave_types'
            && $result['active_leave_types_count'] === 0);
});

test('sync reports no eligible employees when none are in scope', function () {
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $leaveType->update(['status' => 'active']);
    Employee::query()->where('company_id', $company->id)->update(['status' => 'inactive']);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'))
        ->assertSessionHas('leave_balance_sync_result', fn (array $result): bool => $result['message_variant'] === 'no_eligible_employees'
            && $result['eligible_employees_checked'] === 0);
});

test('sync logs company scoped activity with counts', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    createAttendanceLeaveEmployee($company);

    $this->actingAs($user)
        ->from(route('organization.reports.leave-balances.index'))
        ->post(route('organization.reports.leave-balances.sync-missing'));

    $activity = Activity::query()
        ->where('description', 'Synced missing leave balances for current business year')
        ->where('causer_id', $user->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties->get('company_id'))->toBe($company->id)
        ->and($activity->properties->get('new_balance_records_created'))->toBeGreaterThan(0);
});

test('leave balance report get remains read only after sync permission exists', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.sync',
    ]);

    $before = LeaveBalance::query()->count();

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.sync_missing', true));

    expect(LeaveBalance::query()->count())->toBe($before);
});
