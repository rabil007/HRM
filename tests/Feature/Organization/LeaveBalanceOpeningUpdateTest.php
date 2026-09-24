<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveTypeYearBalance;
use App\Support\Settings\CompanyTimezone;
use Inertia\Testing\AssertableInertia as Assert;

test('view permission alone cannot update opening balance and hides can flag', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, ['reports.leave_balance.view', 'employees.view']);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'carried_days' => 0,
        'used_days' => 3,
        'pending_days' => 2,
        'opening_used_days' => 0,
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.leave-balances.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.update_opening', false)
            ->where('balances.0.can_edit_opening', false));

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 8,
            'opening_balance_as_of' => "{$businessYear}-06-01",
        ])
        ->assertForbidden();
});

test('authorized user can set opening used days and remaining recalculates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
        'employees.view',
    ]);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'carried_days' => 0,
        'used_days' => 3,
        'pending_days' => 2,
        'opening_used_days' => 0,
    ]);

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 8,
            'opening_balance_as_of' => "{$businessYear}-06-15",
            'opening_balance_note' => 'Imported from previous HR leave records',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $balance->refresh();

    expect((float) $balance->opening_used_days)->toBe(8.0)
        ->and($balance->opening_balance_as_of?->toDateString())->toBe("{$businessYear}-06-15")
        ->and($balance->opening_balance_note)->toBe('Imported from previous HR leave records')
        ->and((float) $balance->used_days)->toBe(3.0)
        ->and((float) $balance->pending_days)->toBe(2.0)
        ->and((float) $balance->remaining_days)->toBe(17.0)
        ->and($balance->totalUsedDays())->toBe(11.0);

    $this->assertDatabaseHas('activity_log', [
        'description' => 'Updated leave opening balance',
        'subject_type' => LeaveBalance::class,
        'subject_id' => $balance->id,
    ]);
});

test('company isolation blocks cross-company opening updates', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
    ]);

    $other = Company::query()->create([
        'name' => 'Other Opening Co',
        'slug' => 'other-opening-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = createAttendanceLeaveEmployee($other, ['status' => 'active']);
    $foreignType = LeaveType::factory()->for($other)->create(['status' => 'active']);
    $foreignBalance = LeaveBalance::factory()->forEmployee($foreignEmployee)->forLeaveType($foreignType)->create([
        'year' => (int) now(CompanyTimezone::forCompanyId($other->id))->year,
        'entitled_days' => 30,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->patch(route('organization.reports.leave-balances.update-opening', $foreignBalance), [
            'opening_used_days' => 5,
            'opening_balance_as_of' => now()->toDateString(),
        ])
        ->assertNotFound();
});

test('department-restricted user cannot update a hidden employee opening balance', function () {
    ['user' => $user, 'company' => $company, 'employee' => $visible, 'leaveType' => $leaveType, 'department' => $visibleDept] = authorizeLeaveReport();

    $hiddenDept = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Hidden Opening Dept',
        'code' => 'HOD',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $hidden = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'department_id' => $hiddenDept->id,
        'name' => 'Hidden Opening Employee',
    ]);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($hidden)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
    ]);

    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
        'employees.view',
    ]);
    restrictTestRoleEmployeeVisibility($user, $company, [(int) $visibleDept->id]);

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 4,
            'opening_balance_as_of' => "{$businessYear}-03-01",
        ])
        ->assertForbidden();

    expect((float) $balance->fresh()->opening_used_days)->toBe(0.0);
});

test('attendance leave excluded department cannot be updated', function () {
    ['user' => $user, 'company' => $company, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
        'employees.view',
    ]);

    $excluded = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Excluded Opening Dept',
        'code' => 'EOD',
        'status' => 'active',
        'include_in_attendance_leave' => false,
    ]);
    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'department_id' => $excluded->id,
    ]);
    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
    ]);

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 2,
            'opening_balance_as_of' => "{$businessYear}-04-01",
        ])
        ->assertForbidden();
});

test('historical year opening updates are rejected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
    ]);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear - 1,
        'entitled_days' => 30,
    ]);

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 5,
            'opening_balance_as_of' => ($businessYear - 1).'-06-01',
        ])
        ->assertSessionHasErrors('leave_balance');
});

test('resetting opening used days to zero clears as-of and note', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'reports.leave_balance.view',
        'reports.leave_balance.update_opening',
    ]);

    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'used_days' => 3,
        'pending_days' => 0,
        'opening_used_days' => 8,
        'opening_balance_as_of' => "{$businessYear}-01-10",
        'opening_balance_note' => 'Prior import',
    ]);

    $this->actingAs($user)
        ->patch(route('organization.reports.leave-balances.update-opening', $balance), [
            'opening_used_days' => 0,
            'opening_balance_as_of' => "{$businessYear}-01-10",
            'opening_balance_note' => 'should clear',
        ])
        ->assertRedirect();

    $balance->refresh();

    expect((float) $balance->opening_used_days)->toBe(0.0)
        ->and($balance->opening_balance_as_of)->toBeNull()
        ->and($balance->opening_balance_note)->toBeNull()
        ->and((float) $balance->remaining_days)->toBe(27.0);
});

test('leave type year balance exposes total used for my leave consumers', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'carried_days' => 0,
        'opening_used_days' => 8,
        'used_days' => 3,
        'pending_days' => 2,
    ]);

    $presented = app(LeaveTypeYearBalance::class)->forEmployee(
        (int) $company->id,
        (int) $employee->id,
        $businessYear,
    );

    $row = collect($presented)->firstWhere('id', $leaveType->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['opening_used_days'])->toBe(8.0)
        ->and((float) $row['used_days'])->toBe(3.0)
        ->and((float) $row['total_used_days'])->toBe(11.0)
        ->and((float) $row['remaining_days'])->toBe(17.0);
});

test('opening used days reduce leave request availability', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'carried_days' => 0,
        'opening_used_days' => 20,
        'used_days' => 5,
        'pending_days' => 0,
    ]);

    $manager = app(LeaveBalanceManager::class);

    expect(fn () => $manager->assertCanReserve(
        (int) $company->id,
        (int) $employee->id,
        (int) $leaveType->id,
        "{$businessYear}-08-01",
        "{$businessYear}-08-07",
    ))->toThrow(RuntimeException::class);

    $manager->assertCanReserve(
        (int) $company->id,
        (int) $employee->id,
        (int) $leaveType->id,
        "{$businessYear}-08-10",
        "{$businessYear}-08-14",
    );
});
