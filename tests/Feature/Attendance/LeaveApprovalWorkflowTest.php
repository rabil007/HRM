<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\LeaveApprovalResolutionException;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Attendance\ResolveLeaveApprovalChain;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

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
    $employee = createAttendanceLeaveEmployee($company, [
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
        ->assertRedirect(route('attendance.my-leave.index'));

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

test('invalid hr approver blocks submission without exposing configuration to employees', function (
    LeaveApprovalMode $mode,
) {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Without Approve',
    ]);
    ['employee' => $fallback] = makeActionableApprover($company, [
        'name' => 'Unused Fallback For Invalid Hr',
        'work_email' => 'unused-fallback-invalid-hr@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ], $mode);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $hrRole = $hrUser->roles->first();
    expect($hrRole)->not->toBeNull();
    $hrRole->revokePermissionTo('attendance.leave-requests.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
    ]);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->from(route('attendance.my-leave.index'))
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
        ]);

    $response->assertSessionHasErrors('leave_request');

    $message = $response->getSession()->get('errors')->first('leave_request');

    expect($message)
        ->toContain('Your leave approval setup needs attention.')
        ->not->toContain('HR Without Approve')
        ->not->toContain(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE)
        ->and($response->getSession()->get('errors')->first('leave_approval_reason'))->toBe('invalid_designated_approver')
        ->and($response->getSession()->get('errors')->has('leave_approval_diagnostics'))->toBeFalse()
        ->and(LeaveRequest::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('ineligible designated specific employee fails instead of silent substitute', function (
    LeaveApprovalMode $mode,
) {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    ['employee' => $maher, 'user' => $maherUser] = makeActionableApprover($company, [
        'name' => 'Maher',
        'work_email' => 'maher-ineligible@example.com',
    ]);
    ['employee' => $fallback] = makeActionableApprover($company, [
        'name' => 'Would Be Substitute',
        'work_email' => 'would-be-substitute@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, null, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $maher->id,
            'required' => true,
        ],
    ], $mode);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $maherRole = $maherUser->roles->first();
    expect($maherRole)->not->toBeNull();
    $maherRole->revokePermissionTo('attendance.leave-requests.approve');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
    ]);

    $this->actingAs($user);
    $response = $this->withSession(['current_company_id' => $company->id])
        ->from(route('attendance.my-leave.index'))
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ]);

    $response->assertSessionHasErrors('leave_request');
    $message = $response->getSession()->get('errors')->first('leave_request');

    expect($message)
        ->toContain('Your leave approval setup needs attention.')
        ->and($response->getSession()->get('errors')->first('leave_approval_reason'))->toBe('invalid_designated_approver')
        ->and(LeaveRequest::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('inactive designated specific employee fails instead of silent substitute', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    ['employee' => $maher] = makeActionableApprover($company, [
        'name' => 'Inactive Maher',
        'work_email' => 'inactive-maher@example.com',
        'status' => 'inactive',
    ]);
    ['employee' => $fallback] = makeActionableApprover($company, [
        'name' => 'Active Fallback Unused',
        'work_email' => 'active-fallback-unused@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, null, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $maher->id,
            'required' => true,
        ],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    try {
        app(SubmitLeaveRequestWithApprovals::class)->handle(
            companyId: (int) $company->id,
            attributes: [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Invalid specific employee',
            ],
            notify: false,
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        $message = $exception->errors()['leave_request'][0] ?? '';
        expect($message)
            ->toContain('Your leave approval setup needs attention.')
            ->and($exception->errors()['leave_approval_reason'][0])->toBe('invalid_designated_approver')
            ->and($message)->not->toBe(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
    }

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
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

test('self-approval is prevented when requester is the department manager and no alternative exists', function () {
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
        'include_in_attendance_leave' => true,
    ]);

    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'user_id' => $user->id,
        'department_id' => $department->id,
    ]);
    $department->update(['manager_id' => $employee->id]);

    ensureDefaultLeaveApprovalPolicy($company);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $this->actingAs($user);
    $response = $this->withSession(['current_company_id' => $company->id])
        ->from('/attendance/my-leave')
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ]);

    $response->assertSessionHasErrors('leave_request');
    expect(LeaveRequest::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

test('hr can submit own leave when a valid fallback alternative exists', function () {
    ['user' => $admin, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima',
        'work_email' => 'rima-hr@example.com',
        'department_id' => $managed['department']->id,
    ]);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Sara Fallback',
        'work_email' => 'sara-fallback@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    grantCompanyPermissions($hrUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($hrUser);
    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'HR annual leave',
        ])
        ->assertRedirect(route('attendance.my-leave.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $hr->id)->first();

    expect($leaveRequest)->not->toBeNull()
        ->and($leaveRequest->status)->toBe('pending')
        ->and($leaveRequest->approvals)->toHaveCount(2);

    $first = $leaveRequest->approvals->firstWhere('sequence', 1);
    $second = $leaveRequest->approvals->firstWhere('sequence', 2);

    expect($first->approver_employee_id)->toBe($managed['manager']->id)
        ->and($first->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($second->approver_employee_id)->toBe($fallback->id)
        ->and($second->approver_employee_id)->not->toBe($hr->id)
        ->and($second->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and($leaveRequest->approvals->pluck('approver_employee_id')->unique()->count())->toBe(2);

    $this->actingAs($hrUser);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertForbidden();

    $this->actingAs($hrUser);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/reject", [
            'rejection_reason' => 'Cannot self-reject',
        ])
        ->assertForbidden();

    expect($leaveRequest->fresh()->status)->toBe('pending');

    // Silence unused fixture binding from makeLeaveApprovalWorkflowFixtures.
    expect($admin)->not->toBeNull()
        ->and($fallbackUser)->not->toBeNull();
});

test('normal employee manager then hr routing remains unchanged', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Approver',
        'work_email' => 'hr-normal@example.com',
    ]);
    ['employee' => $fallback] = makeActionableApprover($company, [
        'name' => 'Unused Fallback',
        'work_email' => 'unused-fallback@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);
    $employee->update(['user_id' => $user->id]);

    grantCompanyPermissions($user, $company, ['attendance.leave-requests.create']);

    $this->actingAs($user);
    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
        ])
        ->assertRedirect();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $employee->id)->first();

    expect($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($hr->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_user_id)->toBe($hrUser->id);
});

test('manager self-request resolves to parent manager alternative', function () {
    ['user' => $user, 'company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    grantCompanyPermissions($managed['managerUser'], $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $managed['manager']->update(['department_id' => $managed['department']->id]);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $managed['manager']->id, 2026);

    $this->actingAs($managed['managerUser']);
    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $managed['manager']->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ])
        ->assertRedirect(route('attendance.my-leave.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $managed['manager']->id)->first();

    expect($leaveRequest)->not->toBeNull()
        ->and($leaveRequest->status)->toBe('pending')
        ->and($leaveRequest->approvals)->toHaveCount(1)
        ->and($leaveRequest->approvals->first()->approver_employee_id)->toBe($managed['parentManager']->id)
        ->and($leaveRequest->approvals->first()->approver_employee_id)->not->toBe($managed['manager']->id);

    expect($user)->not->toBeNull();
});

test('manager self-request resolves to configured fallback when hierarchy has no other manager', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();

    ['employee' => $manager, 'user' => $managerUser] = makeActionableApprover($company, [
        'name' => 'Solo Manager',
        'work_email' => 'solo-manager@example.com',
    ]);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Fallback Approver',
        'work_email' => 'manager-fallback@example.com',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Solo Dept',
        'code' => 'SOLO',
        'manager_id' => $manager->id,
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $manager->update(['department_id' => $department->id]);

    configureCompanyLeaveApprovalSettings($company, null, $fallback);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $manager->id, 2026);

    grantCompanyPermissions($managerUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($managerUser);
    $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $manager->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ])
        ->assertRedirect(route('attendance.my-leave.index'));

    $leaveRequest = LeaveRequest::query()->where('employee_id', $manager->id)->first();

    expect($leaveRequest->approvals)->toHaveCount(1)
        ->and($leaveRequest->approvals->first()->approver_employee_id)->toBe($fallback->id)
        ->and($leaveRequest->approvals->first()->approver_user_id)->toBe($fallbackUser->id);
});

test('manager plus hr policy preserves distinct required steps when hr submits own leave', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima HR',
        'work_email' => 'rima-distinct@example.com',
        'department_id' => $managed['department']->id,
    ]);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Distinct Fallback',
        'work_email' => 'distinct-fallback@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    grantCompanyPermissions($hrUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Preserve both steps',
        ],
        notify: false,
    );

    expect($leaveRequest->status)->toBe('pending')
        ->and($leaveRequest->approvals)->toHaveCount(2);

    $approverIds = $leaveRequest->approvals->pluck('approver_employee_id')->all();

    expect($approverIds)->not->toContain($hr->id)
        ->and(count(array_unique($approverIds)))->toBe(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($fallback->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_user_id)->toBe($fallbackUser->id)
        ->and($leaveRequest->approvals->every(fn ($step) => (bool) $step->is_required))->toBeTrue();
});

test('hr then department manager self-request preserves natural manager via fallback', function (
    LeaveApprovalMode $mode,
) {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima',
        'work_email' => 'rima-hr-first@example.com',
        'department_id' => $managed['department']->id,
    ]);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Sara',
        'work_email' => 'sara-hr-first@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    // Policy order HR → Department Manager must not steal Adam for the HR step.
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ], $mode);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Preserve natural manager',
        ],
        notify: false,
    );

    expect($leaveRequest->approvalMode())->toBe($mode)
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_type)->toBe(LeaveApprovalApproverType::HrApprover)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($fallback->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_user_id)->toBe($fallbackUser->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_type)->toBe(LeaveApprovalApproverType::DepartmentManager)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->pluck('approver_employee_id'))->not->toContain($hr->id)
        ->and($hrUser)->not->toBeNull();
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('hr then department manager without fallback prefers parent manager for hr step', function (
    LeaveApprovalMode $mode,
) {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima No Fallback',
        'work_email' => 'rima-no-fallback-order@example.com',
        'department_id' => $managed['department']->id,
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, null);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ], $mode);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Reserve Adam for department manager',
        ],
        notify: false,
    );

    expect($leaveRequest->approvalMode())->toBe($mode)
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_type)->toBe(LeaveApprovalApproverType::HrApprover)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['parentManager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_type)->toBe(LeaveApprovalApproverType::DepartmentManager)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->pluck('approver_employee_id')->unique()->count())->toBe(2)
        ->and($leaveRequest->approvals->pluck('approver_employee_id'))->not->toContain($hr->id)
        ->and($hrUser)->not->toBeNull();
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('unsuitable view_all fallback is skipped when restricted from requester department', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company, withParent: true);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima Visibility',
        'work_email' => 'rima-visibility@example.com',
        'department_id' => $managed['department']->id,
    ]);

    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Restricted Fallback',
        'work_email' => 'restricted-fallback@example.com',
    ]);

    // Fallback has view_all + approve but cannot see the requester's department.
    grantCompanyPermissions($fallbackUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ], 'restricted-fallback-role');

    $otherDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Other Visibility Dept',
        'code' => 'OVD',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    restrictTestRoleEmployeeVisibility(
        $fallbackUser,
        $company,
        [(int) $otherDepartment->id],
        'restricted-fallback-role',
    );

    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Skip unapprovable fallback',
        ],
        notify: false,
    );

    expect($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->pluck('approver_employee_id'))->not->toContain($fallback->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['parentManager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($managed['manager']->id)
        ->and(app(LeaveRequestVisibility::class)->assignedApproverCanActOnRequester(
            $fallbackUser,
            $hr->fresh(),
            (int) $company->id,
        ))->toBeFalse()
        ->and($hrUser)->not->toBeNull();
});

test('hr self-request fails when only fallback is view_all restricted from requester department', function () {
    ['company' => $company, 'hr' => $hr, 'hrUser' => $hrUser] = makeHrOwnedDepartmentContext();

    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Only Restricted Fallback',
        'work_email' => 'only-restricted-fallback@example.com',
    ]);
    grantCompanyPermissions($fallbackUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ], 'only-restricted-fallback-role');

    $otherDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Unrelated Dept',
        'code' => 'URD',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    restrictTestRoleEmployeeVisibility(
        $fallbackUser,
        $company,
        [(int) $otherDepartment->id],
        'only-restricted-fallback-role',
    );

    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    try {
        app(SubmitLeaveRequestWithApprovals::class)->handle(
            companyId: (int) $company->id,
            attributes: [
                'employee_id' => $hr->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'No usable alternative',
            ],
            notify: false,
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors()['leave_request'][0] ?? null)
            ->toBe(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
    }

    expect(LeaveRequest::query()->where('employee_id', $hr->id)->exists())->toBeFalse()
        ->and($hrUser)->not->toBeNull();
});

test('shared linked user identity cannot be used as an alternative approver', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $requester, 'user' => $sharedUser] = makeActionableApprover($company, [
        'name' => 'Requester Identity',
        'work_email' => 'requester-identity@example.com',
        'department_id' => $managed['department']->id,
    ]);

    // Second employee record linked to the same user, configured as HR Approver.
    $aliasHr = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $sharedUser->id,
        'name' => 'Alias HR Profile',
        'work_email' => 'alias-hr@example.com',
        'department_id' => $managed['department']->id,
    ]);

    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Safe Fallback',
        'work_email' => 'safe-fallback-identity@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $aliasHr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $requester->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $requester->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Shared user identity',
        ],
        notify: false,
    );

    expect($leaveRequest->approvals)->toHaveCount(1)
        ->and($leaveRequest->approvals->first()->approver_employee_id)->toBe($fallback->id)
        ->and($leaveRequest->approvals->first()->approver_user_id)->toBe($fallbackUser->id)
        ->and($leaveRequest->approvals->first()->approver_employee_id)->not->toBe($aliasHr->id)
        ->and($leaveRequest->approvals->first()->approver_user_id)->not->toBe($sharedUser->id);

    $this->actingAs($sharedUser);
    $this->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertForbidden();
});

test('workflow invariant rejects malformed snapshot where approver shares requester user', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $employee, 'user' => $user] = makeActionableApprover($company, [
        'name' => 'Invariant Requester',
        'work_email' => 'invariant-requester@example.com',
        'department_id' => $managed['department']->id,
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);

    $leaveRequest = createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'total_days' => 2,
        'status' => 'pending',
    ]);

    LeaveRequestApproval::factory()->create([
        'company_id' => $company->id,
        'leave_request_id' => $leaveRequest->id,
        'sequence' => 1,
        'approver_type' => LeaveApprovalApproverType::HrApprover,
        'approver_employee_id' => $managed['manager']->id,
        'approver_user_id' => $user->id,
        'status' => LeaveRequestApprovalStatus::Pending,
        'is_required' => true,
    ]);

    expect(fn () => app(AssertLeaveApprovalWorkflowInvariant::class)->forPendingRequest(
        $leaveRequest->fresh(),
        $leaveRequest->fresh()->approvals,
        $managed['managerUser'],
    ))->toThrow(ValidationException::class);
});

test('manager and hr resolving to the same person keep both required steps via fallback', function (
    LeaveApprovalMode $mode,
) {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    // Department manager is also configured as the default HR approver.
    configureCompanyLeaveApprovalSettings($company, $managed['manager'], null);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Distinct Collapse Fallback',
        'work_email' => 'collapse-fallback@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $managed['manager'], $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ], $mode);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Do not collapse required steps',
        ],
        notify: false,
    );

    expect($leaveRequest->approvalMode())->toBe($mode)
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->firstWhere('sequence', 1)->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_employee_id)->toBe($fallback->id)
        ->and($leaveRequest->approvals->firstWhere('sequence', 2)->approver_user_id)->toBe($fallbackUser->id)
        ->and($leaveRequest->approvals->pluck('approver_employee_id')->unique()->count())->toBe(2)
        ->and($leaveRequest->approvals->pluck('approver_user_id')->unique()->count())->toBe(2);
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('manager and hr same person without alternative fails instead of collapsing', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    configureCompanyLeaveApprovalSettings($company, $managed['manager'], null);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    ['employee' => $employee, 'leaveType' => $leaveType] = makeWorkflowActors($company, $managed['department']);

    try {
        app(SubmitLeaveRequestWithApprovals::class)->handle(
            companyId: (int) $company->id,
            attributes: [
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Collapse forbidden',
            ],
            notify: false,
        );
        expect(false)->toBeTrue();
    } catch (ValidationException $exception) {
        expect($exception->errors()['leave_request'][0] ?? null)
            ->toContain('required approval steps could not be assigned to different authorized people')
            ->not->toContain('own request')
            ->and($exception->errors()['leave_approval_reason'][0])->toBe('duplicate_required_approver');
    }

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->exists())->toBeFalse();
});

/**
 * HR owns her department so the management hierarchy cannot supply a substitute.
 *
 * @return array{company: Company, hr: Employee, hrUser: User, department: Department}
 */
function makeHrOwnedDepartmentContext(): array
{
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima Self Dept',
        'work_email' => 'rima-self-dept@example.com',
    ]);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'HR Self Dept',
        'code' => 'HRSD',
        'manager_id' => $hr->id,
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $hr->update(['department_id' => $department->id]);

    return compact('company', 'hr', 'hrUser', 'department');
}

test('notify-only manager does not consume the authorized alternative for hr self-leave', function (bool $hasFallback) {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr] = makeActionableApprover($company, [
        'department_id' => $managed['department']->id,
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $hasFallback ? $managed['manager'] : null);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => false],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leave = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ],
        notify: false,
    );

    expect($leave->status)->toBe('pending')
        ->and($leave->approvals)->toHaveCount(1)
        ->and($leave->approvals->first()->approver_type)->toBe(LeaveApprovalApproverType::HrApprover)
        ->and($leave->approvals->first()->is_required)->toBeTrue()
        ->and($leave->approvals->first()->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($leave->approvals->first()->approver_employee_id)->toBe($managed['manager']->id)
        ->and($leave->approvals->first()->approver_user_id)->not->toBe($hr->user_id);
})->with([false, true]);

test('self-leave failure gives configuration diagnostics only to settings viewers', function (string $access) {
    $administrator = $access === 'settings_viewer';
    ['company' => $company, 'hr' => $hr, 'hrUser' => $hrUser] = makeHrOwnedDepartmentContext();
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company);
    $fallbackUser->roles()->detach();
    $fallbackUser->forgetCachedPermissions();
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);
    grantCompanyPermissions($hrUser, $company, array_filter([
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        $administrator ? 'attendance.leave-approval-settings.view' : null,
    ]));
    if ($access === 'other_company_settings_viewer') {
        ['company' => $otherCompany] = makeLeaveApprovalWorkflowFixtures();
        grantCompanyPermissions($hrUser, $otherCompany, ['attendance.leave-approval-settings.view'], 'foreign-settings-role');
    }
    $this->actingAs($hrUser);

    $response = $this->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ]);

    $response->assertSessionHasErrors('leave_request');
    $errors = $response->getSession()->get('errors');
    expect($errors->first('leave_request'))
        ->toContain("We couldn't find another authorized person to approve your leave.")
        ->not->toContain((string) $fallback->name)
        ->and($errors->first('leave_approval_reason'))->toBe('self_approval_conflict')
        ->and($errors->has('leave_approval_diagnostics'))->toBe($administrator)
        ->and(LeaveRequest::query()->where('employee_id', $hr->id)->exists())->toBeFalse()
        ->and(LeaveBalance::query()->where('employee_id', $hr->id)->where('year', 2026)->value('pending_days'))->toEqual(0.0);

    if ($administrator) {
        expect($errors->first('leave_approval_diagnostics'))
            ->toContain('Required step 1 (HR Approver)', 'Fallback', 'missing_view_permission', 'missing_approve_permission', 'Leave Approval Settings');
    }
})->with(['employee', 'settings_viewer', 'other_company_settings_viewer']);

test('promoting a notify-only manager to hr alternative preserves required policy order', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);
    ['employee' => $hr] = makeActionableApprover($company, ['department_id' => $managed['department']->id]);
    ['employee' => $firstApprover] = makeActionableApprover($company);
    configureCompanyLeaveApprovalSettings($company, $hr, $managed['manager']);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => false],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $firstApprover->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 30]);

    $leave = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ],
        notify: false,
    );

    $approvals = $leave->approvals->sortBy('sequence')->values();
    expect($approvals)->toHaveCount(2)
        ->and($approvals[0]->approver_employee_id)->toBe($firstApprover->id)
        ->and($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[1]->approver_employee_id)->toBe($managed['manager']->id)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Waiting);
});

test('self-leave diagnostics identify why each configured fallback is rejected', function (string $scenario, string $expected) {
    ['company' => $company, 'hr' => $hr, 'hrUser' => $hrUser] = makeHrOwnedDepartmentContext();
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company);

    match ($scenario) {
        'inactive_employee' => $fallback->update(['status' => 'inactive']),
        'inactive_user' => $fallbackUser->update(['status' => 'inactive']),
        'inactive_membership' => DB::table('company_user')->where('company_id', $company->id)->where('user_id', $fallbackUser->id)->update(['status' => 'inactive']),
        'shared_user' => $fallback->update(['user_id' => $hrUser->id]),
        'missing_user' => $fallback->update(['user_id' => null]),
        'foreign_employee' => $fallback->update(['company_id' => makeLeaveApprovalWorkflowFixtures()['company']->id]),
        'missing_fallback' => null,
    };
    configureCompanyLeaveApprovalSettings($company, $hr, $scenario === 'missing_fallback' ? null : $fallback);
    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    try {
        app(ResolveLeaveApprovalChain::class)->handle($hr->fresh(), (int) $company->id);
        $this->fail('An ineligible fallback must not allow submission.');
    } catch (LeaveApprovalResolutionException $exception) {
        expect($exception->reason->value)->toBe('self_approval_conflict')
            ->and(implode("\n", $exception->diagnostics))->toContain($expected);
    }
})->with([
    ['inactive_employee', 'inactive_employee'],
    ['inactive_user', 'inactive_linked_user'],
    ['inactive_membership', 'inactive_company_membership'],
    ['shared_user', 'requester_identity'],
    ['missing_user', 'missing_linked_user'],
    ['foreign_employee', 'Fallback: not found in this company'],
    ['missing_fallback', 'Fallback: not configured'],
]);

test('hr self-request fails clearly when fallback is missing', function () {
    ['company' => $company, 'hr' => $hr, 'hrUser' => $hrUser] = makeHrOwnedDepartmentContext();
    configureCompanyLeaveApprovalSettings($company, $hr, null);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    grantCompanyPermissions($hrUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($hrUser);
    $response = $this->withSession(['current_company_id' => $company->id])
        ->from(route('attendance.my-leave.index'))
        ->post('/attendance/leave-requests', [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ]);

    $response->assertSessionHasErrors('leave_request');
    expect($response->getSession()->get('errors')->first('leave_request'))
        ->toBe(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE)
        ->and(LeaveRequest::query()->where('employee_id', $hr->id)->exists())->toBeFalse()
        ->and(LeaveBalance::query()->where('employee_id', $hr->id)->where('year', 2026)->value('pending_days'))
        ->toEqual(0.0);
});

test('hr self-request rejects inactive unauthorized and cross-company fallbacks', function (string $scenario) {
    ['company' => $company, 'hr' => $hr, 'hrUser' => $hrUser] = makeHrOwnedDepartmentContext();

    $fallback = match ($scenario) {
        'inactive_employee' => makeActionableApprover($company, [
            'name' => 'Inactive Fallback',
            'work_email' => 'inactive-fallback@example.com',
            'status' => 'inactive',
        ])['employee'],
        'unauthorized' => (function () use ($company) {
            $user = User::factory()->create(['status' => 'active', 'name' => 'No Perms Fallback']);
            DB::table('company_user')->insert([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return Employee::factory()->forCompany($company)->create([
                'status' => 'active',
                'user_id' => $user->id,
                'name' => 'No Perms Fallback',
                'work_email' => 'no-perms-fallback@example.com',
            ]);
        })(),
        'cross_company' => (function () {
            $other = makeLeaveApprovalWorkflowFixtures();
            ['employee' => $foreign] = makeActionableApprover($other['company'], [
                'name' => 'Foreign Fallback',
                'work_email' => 'foreign-fallback@example.com',
            ]);

            return $foreign;
        })(),
        'self_fallback' => $hr,
        default => throw new RuntimeException("Unknown scenario {$scenario}"),
    };

    // Cross-company IDs must not be writable through settings validation in UI,
    // but resolution must still refuse them if present on the settings row.
    $settings = CompanyLeaveApprovalSetting::forCompany($company->id);
    $settings->forceFill([
        'default_hr_approver_employee_id' => $hr->id,
        'fallback_approver_employee_id' => $fallback->id,
    ])->save();

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    grantCompanyPermissions($hrUser, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.create',
        'attendance.leave-requests.approve',
    ]);

    $this->actingAs($hrUser);
    $response = $this->withSession(['current_company_id' => $company->id])
        ->from(route('attendance.my-leave.index'))
        ->post('/attendance/leave-requests', [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
        ]);

    $response->assertSessionHasErrors('leave_request');
    expect($response->getSession()->get('errors')->first('leave_request'))
        ->toBe(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE)
        ->and(LeaveRequest::query()->where('employee_id', $hr->id)->exists())->toBeFalse();
})->with([
    'inactive_employee',
    'unauthorized',
    'cross_company',
    'self_fallback',
]);

test('hr self-request keeps both approval modes with distinct actionable approvers', function (
    LeaveApprovalMode $mode,
) {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima Mode',
        'work_email' => 'rima-mode@example.com',
        'department_id' => $managed['department']->id,
    ]);
    ['employee' => $fallback, 'user' => $fallbackUser] = makeActionableApprover($company, [
        'name' => 'Mode Fallback',
        'work_email' => 'mode-fallback@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fallback);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ], $mode);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Mode coverage',
        ],
        notify: false,
    );

    expect($leaveRequest->approvalMode())->toBe($mode)
        ->and($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->approvals->pluck('approver_employee_id')->unique()->count())->toBe(2)
        ->and($leaveRequest->approvals->pluck('approver_employee_id'))->not->toContain($hr->id);

    $managerStep = $leaveRequest->approvals->firstWhere('approver_employee_id', $managed['manager']->id);
    $fallbackStep = $leaveRequest->approvals->firstWhere('approver_employee_id', $fallback->id);

    expect($managerStep)->not->toBeNull()
        ->and($fallbackStep)->not->toBeNull()
        ->and($fallbackStep->approver_user_id)->toBe($fallbackUser->id);

    if ($mode === LeaveApprovalMode::AllRequired) {
        expect($leaveRequest->approvals->firstWhere('sequence', 1)->status)->toBe(LeaveRequestApprovalStatus::Pending)
            ->and($leaveRequest->approvals->firstWhere('sequence', 2)->status)->toBe(LeaveRequestApprovalStatus::Waiting);
    } else {
        expect($leaveRequest->approvals->every(
            fn ($step) => $step->status === LeaveRequestApprovalStatus::Pending,
        ))->toBeTrue();
    }

    $pendingDays = LeaveBalance::query()
        ->where('employee_id', $hr->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->value('pending_days');

    expect((float) $pendingDays)->toBe(2.0)
        ->and($hrUser)->not->toBeNull();
})->with([
    'all_required' => [LeaveApprovalMode::AllRequired],
    'any_required' => [LeaveApprovalMode::AnyRequired],
]);

test('hr self-request fails when fallback equals the only other required approver', function () {
    ['company' => $company] = makeLeaveApprovalWorkflowFixtures();
    $managed = makeManagedDepartment($company);

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'Rima Colliding Fallback',
        'work_email' => 'rima-collide@example.com',
        'department_id' => $managed['department']->id,
    ]);

    // Fallback is the same person already required as department manager.
    configureCompanyLeaveApprovalSettings($company, $hr, $managed['manager']);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $hr->id, 2026);

    expect(fn () => app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $hr->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Would collapse steps',
        ],
        notify: false,
    ))->toThrow(
        ValidationException::class,
    );

    try {
        app(SubmitLeaveRequestWithApprovals::class)->handle(
            companyId: (int) $company->id,
            attributes: [
                'employee_id' => $hr->id,
                'leave_type_id' => $leaveType->id,
                'start_date' => '2026-06-10',
                'end_date' => '2026-06-11',
                'reason' => 'Would collapse steps',
            ],
            notify: false,
        );
    } catch (ValidationException $exception) {
        expect($exception->errors()['leave_request'][0] ?? null)
            ->toBe(ResolveLeaveApprovalChain::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
    }

    expect(LeaveRequest::query()->where('employee_id', $hr->id)->exists())->toBeFalse()
        ->and($hrUser)->not->toBeNull();
});

test('view_all does not turn leave approvals into a company-wide queue', function () {
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
        ->get('/attendance/leave-approvals?scope=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));

    $this->actingAs($viewer);
    grantCompanyPermissions($viewer, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approvals?scope=all')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));
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

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);
    LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->update(['entitled_days' => 30, 'pending_days' => 0, 'used_days' => 0]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $company->id,
        attributes: [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-12',
            'reason' => 'Coverage test',
        ],
        notify: false,
    );

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
        ->get('/attendance/leave-approvals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leave_requests', 1)
            ->where('leave_requests.0.id', $mine->id));
});

test('legacy assigned_to_me scope cannot show historical approvals in the action queue', function () {
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
        ->get('/attendance/leave-approvals?scope=assigned_to_me')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));

    $this->withSession(['current_company_id' => $company->id])
        ->get('/attendance/leave-approvals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leave_requests', 0));

    $this->put("/attendance/leave-requests/{$historical->id}/approve")
        ->assertForbidden();
});
