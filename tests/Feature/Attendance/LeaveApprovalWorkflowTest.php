<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @return array{user: User, company: Company}
 */
function makeLeaveApprovalWorkflowFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'WF'.fake()->unique()->numerify('##'),
        'name' => 'Workflowland',
        'dial_code' => '+996',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'WF'.fake()->unique()->numerify('##'),
        'name' => 'Workflow Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Workflow Co',
        'slug' => 'wf-'.fake()->unique()->numerify('####'),
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
 * @return array{employee: Employee, leaveType: LeaveType}
 */
function makeWorkflowActors(Company $company, ?Department $department = null): array
{
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $department?->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return ['employee' => $employee, 'leaveType' => $leaveType];
}

test('manager-only policy creates a single pending approval step', function () {
    Mail::fake();
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();

    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Trip',
        ])
        ->assertRedirect(route('attendance.leave-requests.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->first();

    expect($leaveRequest)->not->toBeNull()
        ->and($leaveRequest->approvals)->toHaveCount(1)
        ->and($leaveRequest->approvals->first()->approver_user_id)->toBe($managed['managerUser']->id)
        ->and($leaveRequest->approvals->first()->status)->toBe(LeaveRequestApprovalStatus::Pending);

    Mail::assertQueued(LeaveRequestSubmittedMail::class);
});

test('manager then hr policy creates ordered steps and advances on approve', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Approver',
        'work_email' => 'hr@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
        ])
        ->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->first();
    expect($leaveRequest->approvals)->toHaveCount(2);

    $first = $leaveRequest->approvals->firstWhere('sequence', 1);
    $second = $leaveRequest->approvals->firstWhere('sequence', 2);

    expect($first->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($second->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and($first->approver_user_id)->toBe($managed['managerUser']->id)
        ->and($second->approver_user_id)->toBe($hrUser->id);

    $this->actingAs($managed['managerUser']);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and($first->fresh()->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($second->fresh()->status)->toBe(LeaveRequestApprovalStatus::Pending);

    Mail::fake();
    EmailTemplatesSeeder::seedLeaveRequestApprovedTemplate();

    $this->actingAs($hrUser);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('approved');
});

test('parent manager step resolves distinct parent-level manager', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::ParentManager, 'required' => true],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ])
        ->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->first();

    expect($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($managed['parentManager']->id);
});

test('self-approval is prevented when requester is the department manager', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Self Dept',
        'code' => 'SELF',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
        'department_id' => $department->id,
    ]);
    $department->update(['manager_id' => $employee->id]);

    ensureDefaultLeaveApprovalPolicy($company);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $this->actingAs($user);
    $this->withSession(['current_company_id' => $company->id])
        ->from('/attendance/leave-requests')
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ])
        ->assertSessionHasErrors('leave_request');
});

test('view_all can list all leave requests while approve alone cannot', function () {
    ['user' => $viewer, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $approverOnly = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $approverOnly->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($approverOnly);
    grantCompanyPermissions($approverOnly, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));

    $this->actingAs($viewer);
    grantCompanyPermissions($viewer, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 1));
});

test('approve permission alone cannot approve an unrelated leave request', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ensureDefaultLeaveApprovalPolicy($company);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertForbidden();
});

test('rejection cancels remaining steps and queues decided email', function () {
    Mail::fake();
    EmailTemplatesSeeder::seedLeaveRequestRejectedTemplate();

    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company);
    configureCompanyLeaveApprovalSettings($company, $hr);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['work_email' => 'employee@example.com']);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::DepartmentManager,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
    ]);

    LeaveRequestApproval::factory()->waiting()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 2,
        'approver_type' => LeaveApprovalApproverType::HrApprover,
        'approver_employee_id' => $hr->id,
        'approver_user_id' => $hrUser->id,
        'is_required' => true,
    ]);

    $this->actingAs($managed['managerUser']);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/reject", [
            'rejection_reason' => 'Insufficient coverage',
        ])
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('rejected')
        ->and(LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->where('sequence', 2)->value('status'))
        ->toBe(LeaveRequestApprovalStatus::Cancelled);

    Mail::assertQueued(LeaveRequestDecidedMail::class);
});

test('awaiting_my_approval scope lists only pending steps for the actor', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    $mine = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $other = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-07-10',
        'end_date' => '2026-07-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $mine->id,
        'sequence' => 1,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    LeaveRequestApproval::factory()->waiting()->create([
        'company_id' => $company->id,
        'leave_request_id' => $other->id,
        'sequence' => 1,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
    ]);

    $this->actingAs($managed['managerUser']);
    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=awaiting_my_approval')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $mine->id));
});

test('assigned_to_me includes historical approvals without granting action rights', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Actor',
        'work_email' => 'hr-assigned@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    $historical = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-02',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $historical->id,
        'sequence' => 1,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $managed['managerUser']->id,
        'status' => LeaveRequestApprovalStatus::Approved,
        'acted_at' => now(),
    ]);
    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $historical->id,
        'sequence' => 2,
        'approver_type' => LeaveApprovalApproverType::HrApprover,
        'approver_employee_id' => $hr->id,
        'approver_user_id' => $hrUser->id,
        'status' => LeaveRequestApprovalStatus::Pending,
    ]);

    grantCompanyPermissions($managed['managerUser'], $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($managed['managerUser']);
    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=assigned_to_me')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $historical->id)
            ->where('leave_requests.0.can_approve_current_step', false));

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-requests?scope=awaiting_my_approval')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));

    $this->put("/attendance/leave-requests/{$historical->id}/approve")
        ->assertForbidden();
});
