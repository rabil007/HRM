<?php

use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceLeaveDepartmentScope;
use App\Support\Attendance\LeaveApprovalNeedsActionCounter;
use App\Support\Attendance\LeaveRequestVisibility;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('department include_in_attendance_leave persists and new creates default excluded', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'departments.view',
        'departments.create',
        'departments.update',
    ]);

    $this->actingAs($user)
        ->post('/organization/departments', [
            'name' => 'New Ops',
            'code' => 'NOP',
            'status' => 'active',
        ])
        ->assertRedirect();

    $created = Department::query()->where('company_id', $company->id)->where('code', 'NOP')->first();

    expect($created)->not->toBeNull()
        ->and((bool) $created->include_in_attendance_leave)->toBeFalse();

    $this->actingAs($user)
        ->put("/organization/departments/{$created->id}", [
            'name' => 'New Ops',
            'code' => 'NOP',
            'status' => 'active',
            'include_in_attendance_leave' => true,
        ])
        ->assertRedirect();

    expect((bool) $created->fresh()->include_in_attendance_leave)->toBeTrue();
});

test('department cannot be excluded while pending leave requests exist', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType, 'department' => $department] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'departments.view',
        'departments.update',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->from("/organization/departments/{$department->id}")
        ->put("/organization/departments/{$department->id}", [
            'name' => $department->name,
            'code' => $department->code,
            'status' => 'active',
            'include_in_attendance_leave' => false,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('include_in_attendance_leave');

    expect((bool) $department->fresh()->include_in_attendance_leave)->toBeTrue();
});

test('excluded department employees cannot submit leave and are hidden from approvals queue', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);

    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => false]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    LeaveBalance::factory()->forEmployee($office)->forLeaveType($leaveType)->create(['year' => 2026, 'entitled_days' => 30]);
    LeaveBalance::factory()->forEmployee($marine)->forLeaveType($leaveType)->create(['year' => 2026, 'entitled_days' => 30]);

    $approver = makeActionableApprover($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);

    $balancesBefore = LeaveBalance::query()->sum('pending_days');

    $this->actingAs($user)
        ->post('/attendance/leave-requests', [
            'employee_id' => $marine->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-11',
            'reason' => 'should fail',
        ])
        ->assertSessionHasErrors('employee_id');

    expect(LeaveRequest::query()->where('employee_id', $marine->id)->count())->toBe(0)
        ->and((float) LeaveBalance::query()->sum('pending_days'))->toEqual((float) $balancesBefore);

    $officeRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-12',
        'end_date' => '2026-09-13',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $officeRequest->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $approver['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'sequence' => 1,
    ]);

    $marineRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-14',
        'end_date' => '2026-09-15',
        'total_days' => 2,
        'status' => 'pending',
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $marineRequest->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $approver['user']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'sequence' => 1,
    ]);

    $this->actingAs($approver['user'])
        ->get(route('attendance.leave-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $officeRequest->id)
            ->where('employees', fn ($options) => collect($options)->pluck('id')->all() === [$office->id]));

    expect(app(LeaveApprovalNeedsActionCounter::class)->count($approver['user'], $company->id))->toBe(1);

    $this->actingAs($approver['user'])
        ->get(route('attendance.leave-requests.show', $marineRequest))
        ->assertNotFound();
});

test('view_all approvals queue still respects employee visibility and department inclusion', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => true]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    $approver = makeActionableApprover($company);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
        'attendance.leave-requests.view_all',
    ]);

    $officeRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'status' => 'pending',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $officeRequest->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $user->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'sequence' => 1,
    ]);

    $marineRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $marine->id,
        'leave_type_id' => $leaveType->id,
        'status' => 'pending',
        'start_date' => '2026-09-03',
        'end_date' => '2026-09-04',
        'total_days' => 2,
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $marineRequest->id,
        'approver_employee_id' => $approver['employee']->id,
        'approver_user_id' => $user->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'sequence' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('attendance.leave-approvals.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $marineRequest->id));

    expect(app(LeaveRequestVisibility::class)->canAccess($officeRequest, $user, $company->id))->toBeFalse()
        ->and(AttendanceLeaveDepartmentScope::canAccessEmployee($marine, $company->id))->toBeTrue();
});

test('leave report ignores corrupt cross-company approval children and soft-deleted types remain filterable', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $leaveType->update(['name' => 'Archived Annual', 'status' => 'inactive']);

    $other = Company::query()->create([
        'name' => 'Foreign Co',
        'slug' => 'foreign-co-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $company->country_id,
        'currency_id' => $company->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $foreignEmployee = createAttendanceLeaveEmployee($other, ['name' => 'Foreign Approver Leak']);

    $request = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'status' => 'pending',
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
    ]);

    $localApproverUser = User::factory()->create();
    $foreignApproverUser = User::factory()->create();

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $request->id,
        'approver_employee_id' => $employee->id,
        'approver_user_id' => $localApproverUser->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
        'sequence' => 1,
        'policy_step_label' => 'Local Manager',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $other->id,
        'leave_request_id' => $request->id,
        'approver_employee_id' => $foreignEmployee->id,
        'approver_user_id' => $foreignApproverUser->id,
        'status' => LeaveRequestApprovalStatus::Approved,
        'is_required' => true,
        'sequence' => 2,
        'policy_step_label' => 'Foreign Step',
    ]);

    LeaveRequestApprovalReassignment::query()->create([
        'company_id' => $other->id,
        'leave_request_id' => $request->id,
        'leave_request_approval_id' => null,
        'sequence' => 2,
        'from_approver_name' => 'Foreign From',
        'to_approver_employee_id' => $foreignEmployee->id,
        'to_approver_user_id' => $foreignApproverUser->id,
        'to_approver_name' => 'Foreign To',
        'reassigned_by_name' => 'Foreign Admin',
        'reason' => 'FOREIGN-REASON',
    ]);

    $leaveType->delete();

    $this->actingAs($user)
        ->get(route('organization.reports.leave.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.approval_progress.required_steps', 1)
            ->where('leave_requests.0.approval_chain', fn ($chain) => collect($chain)->pluck('policy_step_label')->all() === ['Local Manager'])
            ->where('leave_requests.0.reassignments', [])
            ->where('filter_options.leave_types', fn ($types) => collect($types)->contains(fn ($type) => (int) $type['id'] === $leaveType->id)));

    expect($this->actingAs($user)->get(route('organization.reports.leave.index'))->getContent())
        ->not->toContain('Foreign Approver Leak')
        ->not->toContain('Foreign From')
        ->not->toContain('FOREIGN-REASON');
});

test('department delete is blocked while pending leave exists and allowed after resolve', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType, 'department' => $department] = authorizeLeaveReport();
    grantCompanyPermissions($user, $company, [
        'departments.view',
        'departments.delete',
    ]);

    $pending = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->from('/organization/departments')
        ->delete("/organization/departments/{$department->id}")
        ->assertRedirect('/organization/departments')
        ->assertSessionHasErrors('department');

    expect(Department::query()->whereKey($department->id)->exists())->toBeTrue();

    $pending->forceFill(['status' => 'approved'])->save();

    $this->actingAs($user)
        ->delete("/organization/departments/{$department->id}")
        ->assertRedirect('/organization/departments')
        ->assertSessionHasNoErrors();

    expect(Department::withTrashed()->find($department->id)?->trashed())->toBeTrue();
});

test('employee cannot move to excluded or null department while pending leave exists', function () {
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();
    $user->update(['current_company_id' => $company->id]);
    $officeDept->update(['include_in_attendance_leave' => true]);
    $marineDept->update(['include_in_attendance_leave' => false]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.update',
    ]);

    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active']);
    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $office->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->from("/organization/employees/{$office->id}")
        ->put("/organization/employees/{$office->id}", [
            'name' => $office->name,
            'department_id' => $marineDept->id,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('department_id');

    expect((int) $office->fresh()->department_id)->toBe((int) $officeDept->id);

    $this->actingAs($user)
        ->from("/organization/employees/{$office->id}")
        ->put("/organization/employees/{$office->id}", [
            'name' => $office->name,
            'department_id' => null,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('department_id');

    $includedSibling = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Twin',
        'code' => 'OFT',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    $this->actingAs($user)
        ->put("/organization/employees/{$office->id}", [
            'name' => $office->name,
            'department_id' => $includedSibling->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect((int) $office->fresh()->department_id)->toBe((int) $includedSibling->id);
});

test('new department database default is excluded while migration backfill stays included', function () {
    ['user' => $user, 'company' => $company] = authorizeLeaveReport();

    $existing = Department::query()->where('company_id', $company->id)->firstOrFail();
    expect((bool) $existing->include_in_attendance_leave)->toBeTrue();

    $id = DB::table('departments')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Raw Default Dept',
        'code' => 'RDD',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((bool) DB::table('departments')->where('id', $id)->value('include_in_attendance_leave'))->toBeFalse();
});
