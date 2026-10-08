<?php

use App\Enums\LeaveTypeCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\EmployeeHireDateChangeGuard;
use App\Support\Attendance\LeaveBalanceManager;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('hire date unchanged does not require acknowledgment', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-02-02',
    ])->assertRedirect();

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2026-02-02');
});

test('hire date changed without annual balances saves normally', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
    ])->assertRedirect();

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2026-04-01');
});

test('hire date changed with annual balance requires acknowledgment', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
        'used_days' => 3,
        'pending_days' => 1,
        'carried_days' => 2,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
    ])
        ->assertSessionHasErrors(EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT);

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2026-02-02');

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2026-04-01');
});

test('hire date preview endpoint scopes annual years without exposing other companies', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    ['company' => $otherCompany] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $otherEmployee = createAttendanceLeaveEmployee($otherCompany, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $otherAnnual = LeaveType::factory()->for($otherCompany)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);

    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2027,
        'entitled_days' => 30,
    ]);
    LeaveBalance::factory()->forEmployee($otherEmployee)->forLeaveType($otherAnnual)->create([
        'year' => 2026,
        'entitled_days' => 30,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->postJson(route('organization.employees.hire-date-change-preview', $employee), [
        'hire_date' => '2026-04-01',
    ])
        ->assertOk()
        ->assertJson([
            'requires_acknowledgment' => true,
            'previous_hire_date' => '2026-02-02',
            'new_hire_date' => '2026-04-01',
            'annual_balance_years' => [2026, 2027],
        ]);
});

test('confirmed hire date change preserves existing annual balance values', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
        'used_days' => 4,
        'pending_days' => 2,
        'carried_days' => 1,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    $after = $balance->fresh();
    expect((float) $after->entitled_days)->toBe(28.0)
        ->and((float) $after->used_days)->toBe(4.0)
        ->and((float) $after->pending_days)->toBe(2.0)
        ->and((float) $after->carried_days)->toBe(1.0);
});

test('new annual allocation after hire date change uses updated hire date', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Dubai'));

    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    app(LeaveBalanceManager::class)->provisionMissingBalanceForYear(
        (int) $company->id,
        (int) $employee->id,
        $annual,
        2027,
    );

    $future = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2027)
        ->first();

    expect($future)->not->toBeNull()
        ->and((float) $future->entitled_days)->toBe(30.0);

    LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->forceDelete();

    app(LeaveBalanceManager::class)->provisionMissingBalanceForYear(
        (int) $company->id,
        (int) $employee->id,
        $annual,
        2026,
    );

    $recreated = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $annual->id)
        ->where('year', 2026)
        ->first();

    expect($recreated)->not->toBeNull()
        ->and((float) $recreated->entitled_days)->toBe(23.0);

    Carbon::setTestNow();
});

test('hire date change with acknowledgment writes a dedicated activity log entry', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    $activity = Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('event', 'hire_date_changed')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->properties->get('previous_hire_date'))->toBe('2026-02-02')
        ->and($activity->properties->get('new_hire_date'))->toBe('2026-04-01')
        ->and($activity->properties->get('acknowledgment_required'))->toBeTrue()
        ->and($activity->properties->get('acknowledgment_provided'))->toBeTrue();
});

test('confirmed hire date change preserves normal employee update activity for other fields', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
        'name' => 'Old Name',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => 'New Name',
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    $updatedActivity = Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    $attributes = $updatedActivity->attribute_changes?->get('attributes');
    $old = $updatedActivity->attribute_changes?->get('old');

    expect($updatedActivity)->not->toBeNull()
        ->and($updatedActivity->company_id)->toBe($company->id)
        ->and($employee->fresh()->name)->toBe('New Name')
        ->and($attributes['name'] ?? null)->toBe('New Name')
        ->and($old['name'] ?? null)->toBe('Old Name')
        ->and($attributes['hire_date'] ?? null)->toBeNull()
        ->and($old['hire_date'] ?? null)->toBeNull();

    expect(Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('event', 'hire_date_changed')
        ->exists())->toBeTrue();
});

test('hire date acknowledgment activity is scoped to the employee company in audit logs', function () {
    ['user' => $user, 'company' => $companyA] = makeLeaveBalanceFixtures();
    ['company' => $companyB] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($companyA, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($companyA)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $companyA, ['employees.update', 'employees.view', 'audit.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    $hireDateActivityId = Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('event', 'hire_date_changed')
        ->value('id');

    expect($hireDateActivityId)->not->toBeNull();

    $response = $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.activity-logs', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
        ->assertOk();

    $response->assertInertia(function (Assert $page) use ($hireDateActivityId): void {
        $logs = $page->toArray()['props']['logs'] ?? [];
        expect(collect($logs)->pluck('id'))->toContain($hireDateActivityId);
    });

    grantCompanyPermissions($user, $companyB, ['audit.view']);

    $this->withSession(['current_company_id' => $companyB->id])
        ->get(route('organization.activity-logs', [
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs', 0),
        );
});

test('hire date change with department move retains department activity logging', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $fromDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'code' => 'OPS-HDC',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $toDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR-HDC',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
        'department_id' => $fromDepartment->id,
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'department_id' => $toDepartment->id,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertRedirect();

    $updatedActivity = Activity::query()
        ->where('subject_type', Employee::class)
        ->where('subject_id', $employee->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    $attributes = $updatedActivity->attribute_changes?->get('attributes');
    $old = $updatedActivity->attribute_changes?->get('old');

    expect($updatedActivity)->not->toBeNull()
        ->and($employee->fresh()->department_id)->toBe($toDepartment->id)
        ->and((int) ($attributes['department_id'] ?? 0))->toBe($toDepartment->id)
        ->and((int) ($old['department_id'] ?? 0))->toBe($fromDepartment->id);
});

test('users without employee update permission cannot change hire date with annual balances', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company, [
        'hire_date' => '2026-02-02',
    ]);
    $annual = LeaveType::factory()->for($company)->create([
        'category' => LeaveTypeCategory::Annual,
        'days_per_year' => 30,
        'status' => 'active',
    ]);
    LeaveBalance::factory()->forEmployee($employee)->forLeaveType($annual)->create([
        'year' => 2026,
        'entitled_days' => 28,
    ]);

    grantCompanyPermissions($user, $company, ['employees.view']);
    $this->actingAs($user);

    $this->put(route('organization.employees.update', $employee), [
        'name' => $employee->name,
        'hire_date' => '2026-04-01',
        EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => true,
    ])->assertForbidden();

    expect($employee->fresh()->hire_date?->toDateString())->toBe('2026-02-02');
});

test('users without employee update permission cannot preview hire date change', function () {
    ['user' => $user, 'company' => $company] = makeLeaveBalanceFixtures();
    $employee = createAttendanceLeaveEmployee($company);

    grantCompanyPermissions($user, $company, ['employees.view']);
    $this->actingAs($user);

    $this->postJson(route('organization.employees.hire-date-change-preview', $employee), [
        'hire_date' => '2026-04-01',
    ])->assertForbidden();
});
