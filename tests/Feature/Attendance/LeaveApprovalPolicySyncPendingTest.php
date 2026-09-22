<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\LeaveBalanceManager;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     otherCompany: Company,
 *     otherUser: User,
 *     department: Department,
 *     manager: Employee,
 *     managerUser: User,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     policy: LeaveApprovalPolicy
 * }
 */
function makeLeaveApprovalPolicySyncFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $otherUser = User::factory()->create(['status' => 'active']);

    $country = Country::query()->create([
        'code' => 'PS'.fake()->unique()->numerify('##'),
        'name' => 'Policy Sync Land',
        'dial_code' => '+977',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'PS'.fake()->unique()->numerify('##'),
        'name' => 'Policy Sync Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Policy Sync Co',
        'slug' => 'ps-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Sync Co',
        'slug' => 'os-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    foreach ([[$company, $user], [$otherCompany, $otherUser]] as [$targetCompany, $targetUser]) {
        DB::table('company_user')->insert([
            'company_id' => $targetCompany->id,
            'user_id' => $targetUser->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $managed = makeManagedDepartment($company);
    configureCompanyLeaveApprovalSettings($company, null, $managed['manager']);

    $policy = ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $user->id,
    ]);

    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'user' => $user,
        'company' => $company,
        'otherCompany' => $otherCompany,
        'otherUser' => $otherUser,
        'department' => $managed['department'],
        'manager' => $managed['manager'],
        'managerUser' => $managed['managerUser'],
        'employee' => $employee,
        'leaveType' => $leaveType,
        'policy' => $policy,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function submitPendingLeaveForSync(array $fixtures, array $overrides = []): LeaveRequest
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = $fixtures;

    $payload = array_merge([
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Sync candidate',
    ], $overrides);

    $response = test()->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', $payload);

    if ($response->exception) {
        throw $response->exception;
    }

    $response->assertRedirect()->assertSessionHasNoErrors();

    return LeaveRequest::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->whereDate('start_date', $payload['start_date'])
        ->latest('id')
        ->firstOrFail();
}

test('authorized user can preview pending leave approval policy sync', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'policy' => $policy] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-approval-policies.update',
    ]);

    $leaveRequest = submitPendingLeaveForSync($fixtures);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertOk()
        ->assertJson([
            'policy_id' => $policy->id,
            'eligible_count' => 1,
            'eligible_leave_request_ids' => [$leaveRequest->id],
            'skipped_approval_started_count' => 0,
        ]);
});

test('unauthorized user cannot preview or execute policy sync', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'policy' => $policy] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-approval-policies.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertForbidden();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertForbidden();
});

test('preview includes untouched pending chains and excludes acted or terminal requests', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'policy' => $policy, 'managerUser' => $managerUser] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
        'attendance.leave-approval-policies.update',
    ]);

    $eligible = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
        'reason' => 'Eligible untouched',
    ]);

    $acted = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
        'reason' => 'Acted',
    ]);

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$acted->id}/approve")
        ->assertRedirect();

    $approved = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-20',
        'end_date' => '2026-06-21',
        'reason' => 'Will approve fully',
    ]);
    // Manager-only policy: one approval completes the request.
    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$approved->id}/approve")
        ->assertRedirect();
    expect($approved->fresh()->status)->toBe('approved');

    $rejected = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
        'reason' => 'Will reject',
    ]);
    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$rejected->id}/reject", [
            'rejection_reason' => 'Not allowed',
        ])
        ->assertRedirect();
    expect($rejected->fresh()->status)->toBe('rejected');

    $cancelled = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-07-10',
        'end_date' => '2026-07-11',
        'reason' => 'Will cancel',
    ]);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
        'attendance.leave-approval-policies.update',
    ]);
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$cancelled->id}/cancel", [
            'cancellation_reason' => 'Changed plans',
        ])
        ->assertRedirect();
    expect($cancelled->fresh()->status)->toBe('cancelled');

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertOk()
        ->json();

    expect($response['eligible_count'])->toBe(1)
        ->and($response['eligible_leave_request_ids'])->toBe([$eligible->id])
        ->and($response['skipped_approval_started_count'])->toBe(0)
        ->and($response['skipped_completed_or_ineligible_count'])->toBeGreaterThanOrEqual(3);
});

test('preview excludes pending requests that resolve to a different policy', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'policy' => $defaultPolicy, 'employee' => $employee] = $fixtures;

    $otherManaged = makeManagedDepartment($company);
    $otherPolicy = LeaveApprovalPolicy::factory()
        ->forCompany($company)
        ->withSteps([
            ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ])
        ->create(['name' => 'Other Dept Policy', 'status' => 'active', 'is_default' => false]);

    $otherManaged['department']->update(['leave_approval_policy_id' => $otherPolicy->id]);

    $otherEmployee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'department_id' => $otherManaged['department']->id,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $otherEmployee->id, 2026);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-approval-policies.update',
    ]);

    $defaultRequest = submitPendingLeaveForSync($fixtures);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => $fixtures['leaveType']->id,
            'start_date' => '2026-08-01',
            'end_date' => '2026-08-02',
            'reason' => 'Other policy',
        ])
        ->assertRedirect();

    $otherRequest = LeaveRequest::query()
        ->where('employee_id', $otherEmployee->id)
        ->latest('id')
        ->firstOrFail();

    $defaultPreview = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $defaultPolicy))
        ->assertOk()
        ->json();

    expect($defaultPreview['eligible_leave_request_ids'])->toContain($defaultRequest->id)
        ->and($defaultPreview['eligible_leave_request_ids'])->not->toContain($otherRequest->id);

    $otherPreview = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $otherPolicy))
        ->assertOk()
        ->json();

    expect($otherPreview['eligible_leave_request_ids'])->toContain($otherRequest->id)
        ->and($otherPreview['eligible_leave_request_ids'])->not->toContain($defaultRequest->id)
        ->and($employee->id)->not->toBe($otherEmployee->id);
});

test('preview never includes cross-company leave requests', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'otherUser' => $otherUser, 'policy' => $policy] = $fixtures;

    $otherManaged = makeManagedDepartment($otherCompany);
    $otherPolicy = ensureDefaultLeaveApprovalPolicy($otherCompany);
    $otherEmployee = createAttendanceLeaveEmployee($otherCompany, [
        'status' => 'active',
        'department_id' => $otherManaged['department']->id,
        'user_id' => $otherUser->id,
    ]);
    $otherLeaveType = LeaveType::factory()->for($otherCompany)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $otherCompany->id, (int) $otherEmployee->id, 2026);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-approval-policies.update',
    ]);
    grantCompanyPermissions($otherUser, $otherCompany, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-approval-policies.update',
    ]);

    submitPendingLeaveForSync($fixtures);

    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => $otherLeaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Foreign company',
        ])
        ->assertRedirect();

    $foreignRequest = LeaveRequest::query()
        ->where('company_id', $otherCompany->id)
        ->latest('id')
        ->firstOrFail();

    $preview = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertOk()
        ->json();

    expect($preview['eligible_leave_request_ids'])->not->toContain($foreignRequest->id);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $otherPolicy))
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $otherPolicy))
        ->assertNotFound();
});

test('sync rebuilds eligible pending approval chains without duplicating active steps', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    [
        'user' => $user,
        'company' => $company,
        'policy' => $policy,
        'manager' => $manager,
    ] = $fixtures;

    ['employee' => $hr] = makeActionableApprover($company, [
        'name' => 'HR Sync Approver',
        'work_email' => 'hr-sync@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $manager);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-approval-policies.update',
    ]);

    $leaveRequest = submitPendingLeaveForSync($fixtures);
    expect($leaveRequest->approvals)->toHaveCount(1);

    // Persist a second required step on the saved policy, then sync.
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-approval-policies.update', $policy), [
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'id' => $policy->steps()->orderBy('sequence')->firstOrFail()->id,
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
                [
                    'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect(route('attendance.leave-approval-policies.index'))
        ->assertSessionHas('success');

    $leaveRequest->refresh();
    $approvals = $leaveRequest->approvals()->orderBy('sequence')->get();

    expect($approvals)->toHaveCount(2)
        ->and($approvals->whereIn('status', [
            LeaveRequestApprovalStatus::Pending,
            LeaveRequestApprovalStatus::Waiting,
        ])->count())->toBe(2)
        ->and($approvals->first()->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and((int) $approvals->first()->policy_id)->toBe((int) $policy->id)
        ->and($approvals->pluck('approver_type')->map->value->all())->toBe([
            LeaveApprovalApproverType::DepartmentManager->value,
            LeaveApprovalApproverType::HrApprover->value,
        ]);

    // Idempotent re-run does not duplicate steps.
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect();

    expect($leaveRequest->fresh()->approvals)->toHaveCount(2);
});

test('sync skips acted approved rejected cancelled and foreign-company requests', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    [
        'user' => $user,
        'company' => $company,
        'policy' => $policy,
        'managerUser' => $managerUser,
        'otherCompany' => $otherCompany,
        'otherUser' => $otherUser,
    ] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.update',
        'attendance.leave-requests.approve',
        'attendance.leave-approval-policies.update',
    ]);

    $eligible = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-02',
    ]);

    $approved = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-11',
    ]);
    $approvedApprovalsBefore = $approved->approvals()->pluck('id')->all();
    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$approved->id}/approve")
        ->assertRedirect();

    $rejected = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-06-20',
        'end_date' => '2026-06-21',
    ]);
    $rejectedApprovalsBefore = $rejected->approvals()->pluck('id')->all();
    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$rejected->id}/reject", [
            'rejection_reason' => 'No',
        ])
        ->assertRedirect();

    $cancelled = submitPendingLeaveForSync($fixtures, [
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-02',
    ]);
    $cancelledApprovalsBefore = $cancelled->approvals()->pluck('id')->all();
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$cancelled->id}/cancel", [
            'cancellation_reason' => 'Cancel',
        ])
        ->assertRedirect();

    $otherManaged = makeManagedDepartment($otherCompany);
    ensureDefaultLeaveApprovalPolicy($otherCompany);
    $otherEmployee = createAttendanceLeaveEmployee($otherCompany, [
        'status' => 'active',
        'department_id' => $otherManaged['department']->id,
        'user_id' => $otherUser->id,
    ]);
    $otherLeaveType = LeaveType::factory()->for($otherCompany)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $otherCompany->id, (int) $otherEmployee->id, 2026);
    grantCompanyPermissions($otherUser, $otherCompany, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
    ]);
    $this->actingAs($otherUser)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->post('/attendance/leave-requests', [
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => $otherLeaveType->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Foreign',
        ])
        ->assertRedirect();
    $foreign = LeaveRequest::query()->where('company_id', $otherCompany->id)->latest('id')->firstOrFail();
    $foreignApprovalsBefore = $foreign->approvals()->pluck('id')->all();

    ['employee' => $hr] = makeActionableApprover($company, [
        'name' => 'HR After Sync',
        'work_email' => 'hr-after-sync@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $fixtures['manager']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-approval-policies.update', $policy), [
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'id' => $policy->steps()->orderBy('sequence')->firstOrFail()->id,
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
                [
                    'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($eligible->fresh()->approvals)->toHaveCount(2)
        ->and($approved->fresh()->approvals()->pluck('id')->all())->toBe($approvedApprovalsBefore)
        ->and($approved->fresh()->status)->toBe('approved')
        ->and($rejected->fresh()->approvals()->pluck('id')->all())->toBe($rejectedApprovalsBefore)
        ->and($rejected->fresh()->status)->toBe('rejected')
        ->and($cancelled->fresh()->approvals()->pluck('id')->all())->toBe($cancelledApprovalsBefore)
        ->and($cancelled->fresh()->status)->toBe('cancelled')
        ->and($foreign->fresh()->approvals()->pluck('id')->all())->toBe($foreignApprovalsBefore);
});

test('sync skips a request that becomes approved after preview', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    [
        'user' => $user,
        'company' => $company,
        'policy' => $policy,
        'managerUser' => $managerUser,
    ] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
        'attendance.leave-approval-policies.update',
    ]);

    $leaveRequest = submitPendingLeaveForSync($fixtures);
    $approvalIdsBefore = $leaveRequest->approvals()->pluck('id')->all();

    $preview = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertOk()
        ->json();

    expect($preview['eligible_count'])->toBe(1);

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('approved');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect()
        ->assertSessionHas('success', 'No eligible pending leave requests were found.');

    expect($leaveRequest->fresh()->approvals()->pluck('id')->all())->toBe($approvalIdsBefore)
        ->and($leaveRequest->fresh()->status)->toBe('approved');
});

test('synchronization creates policy and per-request audit activity', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'policy' => $policy, 'manager' => $manager] = $fixtures;

    ['employee' => $hr] = makeActionableApprover($company, [
        'name' => 'HR Audit Sync',
        'work_email' => 'hr-audit-sync@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $manager);

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-approval-policies.update',
    ]);

    $leaveRequest = submitPendingLeaveForSync($fixtures);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-approval-policies.update', $policy), [
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'id' => $policy->steps()->orderBy('sequence')->firstOrFail()->id,
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
                [
                    'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect();

    $policyActivity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', LeaveApprovalPolicy::class)
        ->where('subject_id', $policy->id)
        ->get()
        ->first(fn (Activity $activity) => $activity->properties->get('event') === 'leave_approval_policy.pending_requests_synced');

    expect($policyActivity)->not->toBeNull()
        ->and((int) $policyActivity->properties->get('synchronized_count'))->toBe(1)
        ->and($policyActivity->properties->get('synchronized_leave_request_ids'))->toContain($leaveRequest->id);

    $requestActivity = Activity::query()
        ->where('company_id', $company->id)
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $leaveRequest->id)
        ->get()
        ->first(fn (Activity $activity) => $activity->properties->get('event') === 'leave_approval_chain_rebuilt'
            && $activity->properties->get('reason') === 'approval policy synchronized');

    expect($requestActivity)->not->toBeNull()
        ->and($requestActivity->properties->get('previous_approvals'))->toBeArray()
        ->and($requestActivity->properties->get('new_approvals'))->toBeArray();
});

test('preview counts pending requests where a required approver has already acted', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    [
        'user' => $user,
        'company' => $company,
        'policy' => $policy,
        'manager' => $manager,
        'managerUser' => $managerUser,
    ] = $fixtures;

    ['employee' => $hr, 'user' => $hrUser] = makeActionableApprover($company, [
        'name' => 'HR Partial',
        'work_email' => 'hr-partial@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($company, $hr, $manager);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
        'attendance.leave-approval-policies.update',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put(route('attendance.leave-approval-policies.update', $policy), [
            'name' => $policy->name,
            'description' => $policy->description,
            'is_default' => true,
            'status' => 'active',
            'steps' => [
                [
                    'id' => $policy->steps()->orderBy('sequence')->firstOrFail()->id,
                    'approver_type' => LeaveApprovalApproverType::DepartmentManager->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
                [
                    'approver_type' => LeaveApprovalApproverType::HrApprover->value,
                    'approver_employee_id' => null,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $leaveRequest = submitPendingLeaveForSync($fixtures);
    expect($leaveRequest->approvals)->toHaveCount(2)
        ->and($leaveRequest->status)->toBe('pending');

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('pending');

    $preview = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-preview', $policy))
        ->assertOk()
        ->json();

    expect($preview['eligible_count'])->toBe(0)
        ->and($preview['skipped_approval_started_count'])->toBe(1);

    $approvalsBefore = $leaveRequest->fresh()->approvals()->pluck('id')->sort()->values()->all();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('attendance.leave-approval-policies.sync-pending', $policy))
        ->assertRedirect();

    expect($leaveRequest->fresh()->approvals()->pluck('id')->sort()->values()->all())->toBe($approvalsBefore)
        ->and($hrUser->id)->toBeInt();
});

test('new leave submission and approval still work after sync feature', function () {
    $fixtures = makeLeaveApprovalPolicySyncFixtures();
    ['user' => $user, 'company' => $company, 'managerUser' => $managerUser] = $fixtures;

    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.create',
        'attendance.leave-requests.view',
        'attendance.leave-requests.approve',
    ]);

    $leaveRequest = submitPendingLeaveForSync($fixtures);
    expect($leaveRequest->status)->toBe('pending')
        ->and($leaveRequest->approvals)->toHaveCount(1);

    $this->actingAs($managerUser)
        ->withSession(['current_company_id' => $company->id])
        ->put("/attendance/leave-requests/{$leaveRequest->id}/approve")
        ->assertRedirect();

    expect($leaveRequest->fresh()->status)->toBe('approved');
});
