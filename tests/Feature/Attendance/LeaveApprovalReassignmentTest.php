<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\ReassignLeaveRequestApproval;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAuthorization;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     step1: array{employee: Employee, user: User},
 *     step2: array{employee: Employee, user: User},
 *     step3: array{employee: Employee, user: User},
 *     replacement: array{employee: Employee, user: User},
 *     admin: User
 * }
 */
function makeLeaveReassignmentContext(): array
{
    $country = Country::query()->create([
        'code' => 'RA'.fake()->unique()->numerify('##'),
        'name' => 'Reassignland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'RA'.fake()->unique()->numerify('##'),
        'name' => 'Reassign Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Reassign Co',
        'slug' => 'ra-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $step1 = makeActionableApprover($company, ['name' => 'Mohamed', 'work_email' => 'mohamed-ra@example.com']);
    $step2 = makeActionableApprover($company, ['name' => 'Rima', 'work_email' => 'rima-ra@example.com']);
    $step3 = makeActionableApprover($company, ['name' => 'Ahmed', 'work_email' => 'ahmed-ra@example.com']);
    $replacement = makeActionableApprover($company, ['name' => 'Sara', 'work_email' => 'sara-ra@example.com']);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step1['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step2['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step3['employee']->id, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'requester-ra@example.com',
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 40,
    ]);

    $admin = User::factory()->create(['status' => 'active', 'name' => 'HR Admin']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return [
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'step1' => $step1,
        'step2' => $step2,
        'step3' => $step3,
        'replacement' => $replacement,
        'admin' => $admin,
    ];
}

function grantLeaveReassignmentPermissions(User $user, Company $company): void
{
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.reassign_approval',
    ]);
}

function submitMultiStepLeaveForReassignment(array $context, string $start = '2026-03-02', string $end = '2026-03-04'): LeaveRequest
{
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => $start,
            'end_date' => $end,
            'reason' => 'Reassignment fixture',
        ],
        notify: false,
    );
}

function advanceFirstStepForReassignment(array $context, LeaveRequest $leaveRequest): LeaveRequest
{
    return app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['step1']['user'],
        (int) $context['company']->id,
        'Approved by Mohamed',
    );
}

function balanceSnapshotFor(LeaveRequest $leaveRequest): array
{
    $balance = LeaveBalance::query()
        ->where('company_id', $leaveRequest->company_id)
        ->where('employee_id', $leaveRequest->employee_id)
        ->where('leave_type_id', $leaveRequest->leave_type_id)
        ->where('year', 2026)
        ->firstOrFail();

    return [
        'pending_days' => (string) $balance->pending_days,
        'used_days' => (string) $balance->used_days,
        'remaining_days' => (string) $balance->remaining_days,
    ];
}

test('privileged user can reassign current required pending approval without changing history or balances', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);

    $leaveRequest = submitMultiStepLeaveForReassignment($context);
    $leaveRequest = advanceFirstStepForReassignment($context, $leaveRequest);

    $beforeBalances = balanceSnapshotFor($leaveRequest);
    $approved = $leaveRequest->approvals->firstWhere('sequence', 1);
    $waiting = $leaveRequest->approvals->firstWhere('sequence', 3);
    $pending = $leaveRequest->approvals->firstWhere('sequence', 2);

    expect($pending->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and((int) $pending->approver_employee_id)->toBe((int) $context['step2']['employee']->id);

    $policySnapshot = [
        'policy_id' => $pending->policy_id,
        'policy_name' => $pending->policy_name,
        'policy_step_id' => $pending->policy_step_id,
        'policy_step_label' => $pending->policy_step_label,
        'approver_type' => $pending->approver_type,
        'source_department_id' => $pending->source_department_id,
        'sequence' => $pending->sequence,
        'is_required' => $pending->is_required,
        'acted_at' => $pending->acted_at,
        'comments' => $pending->comments,
        'status' => $pending->status,
    ];

    $fresh = app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Rima left company.',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    );

    $fresh->load('approvals');
    $step1 = $fresh->approvals->firstWhere('sequence', 1);
    $step2 = $fresh->approvals->firstWhere('sequence', 2);
    $step3 = $fresh->approvals->firstWhere('sequence', 3);

    expect($fresh->status)->toBe('pending')
        ->and($step1->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and((int) $step1->approver_employee_id)->toBe((int) $approved->approver_employee_id)
        ->and($step1->acted_at?->toIso8601String())->toBe($approved->acted_at?->toIso8601String())
        ->and($step1->comments)->toBe($approved->comments)
        ->and($step2->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($step2->acted_at)->toBeNull()
        ->and((int) $step2->approver_employee_id)->toBe((int) $context['replacement']['employee']->id)
        ->and((int) $step2->approver_user_id)->toBe((int) $context['replacement']['user']->id)
        ->and($step2->policy_id)->toBe($policySnapshot['policy_id'])
        ->and($step2->policy_name)->toBe($policySnapshot['policy_name'])
        ->and($step2->policy_step_id)->toBe($policySnapshot['policy_step_id'])
        ->and($step2->policy_step_label)->toBe($policySnapshot['policy_step_label'])
        ->and($step2->approver_type)->toBe($policySnapshot['approver_type'])
        ->and($step2->source_department_id)->toBe($policySnapshot['source_department_id'])
        ->and($step2->sequence)->toBe($policySnapshot['sequence'])
        ->and($step2->is_required)->toBeTrue()
        ->and($step3->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and((int) $step3->approver_employee_id)->toBe((int) $waiting->approver_employee_id)
        ->and(balanceSnapshotFor($fresh))->toBe($beforeBalances);

    $history = LeaveRequestApprovalReassignment::query()
        ->where('leave_request_id', $fresh->id)
        ->sole();

    expect((int) $history->from_approver_employee_id)->toBe((int) $context['step2']['employee']->id)
        ->and((int) $history->to_approver_employee_id)->toBe((int) $context['replacement']['employee']->id)
        ->and($history->reason)->toBe('Rima left company.')
        ->and((int) $history->reassigned_by_user_id)->toBe((int) $context['admin']->id)
        ->and($history->from_approver_name)->toBe('Rima')
        ->and($history->to_approver_name)->toBe('Sara');

    $activity = Activity::query()
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $fresh->id)
        ->where('description', 'Leave approval reassigned')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['from_approver_name'])->toBe('Rima')
        ->and($activity->properties['to_approver_name'])->toBe('Sara')
        ->and($activity->properties['reason'])->toBe('Rima left company.')
        ->and((int) $activity->causer_id)->toBe((int) $context['admin']->id);

    expect(
        app(LeaveRequestAuthorization::class)->canApproveCurrentStep(
            $fresh,
            $context['replacement']['user'],
            (int) $context['company']->id,
        ),
    )->toBeTrue()
        ->and(
            app(LeaveRequestAuthorization::class)->canApproveCurrentStep(
                $fresh,
                $context['step2']['user'],
                (int) $context['company']->id,
            ),
        )->toBeFalse();
});

test('reassignment reason is required and current approver cannot be selected', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: '   ',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['step2']['employee']->id,
        reason: 'No-op attempt',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    expect(LeaveRequestApprovalReassignment::query()->count())->toBe(0);
});

test('leave requester and ineligible employees cannot become the replacement approver', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['employee']->id,
        reason: 'Requester',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['step1']['employee']->id,
        reason: 'Duplicate required approver',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $inactiveEmployee = makeActionableApprover($context['company'], ['name' => 'Inactive Emp']);
    $inactiveEmployee['employee']->forceFill(['status' => 'inactive'])->save();

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $inactiveEmployee['employee']->id,
        reason: 'Inactive employee',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $noUser = Employee::factory()->forCompany($context['company'])->create([
        'status' => 'active',
        'user_id' => null,
        'name' => 'No User',
    ]);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $noUser->id,
        reason: 'No linked user',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $inactiveUserPair = makeActionableApprover($context['company'], ['name' => 'Inactive User Emp']);
    $inactiveUserPair['user']->forceFill(['status' => 'inactive'])->save();

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $inactiveUserPair['employee']->id,
        reason: 'Inactive linked user',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $noMembership = makeActionableApprover($context['company'], ['name' => 'No Membership']);
    DB::table('company_user')
        ->where('company_id', $context['company']->id)
        ->where('user_id', $noMembership['user']->id)
        ->update(['status' => 'inactive']);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $noMembership['employee']->id,
        reason: 'No active membership',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $missingApprove = makeActionableApprover($context['company'], ['name' => 'View Only']);
    grantCompanyPermissions($missingApprove['user'], $context['company'], [
        'attendance.leave-requests.view',
    ], 'view-only-'.$missingApprove['user']->id);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $missingApprove['employee']->id,
        reason: 'Missing approve',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $missingView = makeActionableApprover($context['company'], ['name' => 'Approve Only']);
    grantCompanyPermissions($missingView['user'], $context['company'], [
        'attendance.leave-requests.approve',
    ], 'approve-only-'.$missingView['user']->id);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $missingView['employee']->id,
        reason: 'Missing view',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);
});

test('cross-company employees and requests are rejected safely', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    $other = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($other['admin'], $other['company']);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $other['replacement']['employee']->id,
        reason: 'Cross company employee',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $this->actingAs($other['admin'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $other['replacement']['employee']->id,
            'expected_approver_employee_id' => $context['step2']['employee']->id,
            'reassignment_reason' => 'Cross company request',
        ])
        ->assertNotFound();
});

test('users without reassign permission cannot reassign', function () {
    $context = makeLeaveReassignmentContext();
    grantCompanyPermissions($context['admin'], $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $context['replacement']['employee']->id,
            'expected_approver_employee_id' => $context['step2']['employee']->id,
            'reassignment_reason' => 'Should fail',
        ])
        ->assertForbidden();
});

test('terminal requests cannot be reassigned', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);

    $approved = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-04-01', '2026-04-02'));
    app(ApproveLeaveRequestStep::class)->handle($approved, $context['step2']['user'], (int) $context['company']->id);
    $approved = app(ApproveLeaveRequestStep::class)->handle($approved->fresh(), $context['step3']['user'], (int) $context['company']->id);
    expect($approved->status)->toBe('approved');

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $approved,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Approved',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $rejected = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-05-04', '2026-05-05'));
    app(RejectLeaveRequestStep::class)->handle(
        $rejected,
        $context['step2']['user'],
        (int) $context['company']->id,
        'No',
    );
    expect($rejected->fresh()->status)->toBe('rejected');

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $rejected->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Rejected',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $cancelled = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-06-01', '2026-06-02'));
    app(CancelLeaveRequestWorkflow::class)->handle(
        $cancelled,
        $context['admin'],
        (int) $context['company']->id,
        'Cancel',
    );

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $cancelled->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Cancelled',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);
});

test('concurrent workflow advancement rejects stale reassignment', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['step2']['user'],
        (int) $context['company']->id,
        'Rima approved first',
    );

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Stale dialog',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    expect(LeaveRequestApprovalReassignment::query()->count())->toBe(0);
});

test('new approver eligibility is rechecked at execution time', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    $context['replacement']['user']->forceFill(['status' => 'inactive'])->save();

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Became ineligible',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);
});

test('new approver receives action-required email after commit when enabled and not when disabled', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $this->seed(EmailTemplatesSeeder::class);
    EmailTemplate::query()
        ->where('slug', 'leave_request_approver_action_required')
        ->update(['enabled' => true]);

    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    Mail::fake();

    app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Notify Sara',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    );

    Mail::assertQueued(LeaveRequestSubmittedMail::class);

    CompanyLeaveApprovalSetting::query()->updateOrCreate(
        ['company_id' => $context['company']->id],
        [
            'email_notifications_enabled' => false,
            'notify_next_approver' => true,
        ],
    );

    $leaveRequest2 = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-07-06', '2026-07-07'));
    Mail::fake();

    app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest2,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Notifications off',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    );

    Mail::assertNothingQueued();
});

test('failed reassignment transaction sends no email', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $this->seed(EmailTemplatesSeeder::class);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    Mail::fake();

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['step2']['employee']->id,
        reason: 'No-op should not mail',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    Mail::assertNothingQueued();
});

test('detail page exposes can_reassign_current_approval only for privileged actors on pending requests', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leave_request.can_reassign_current_approval', true)
            ->has('reassignment_approver_candidates')
            ->where('reassignment_approver_candidates', fn ($candidates) => collect($candidates)
                ->contains(fn ($candidate) => (int) $candidate['id'] === (int) $context['replacement']['employee']->id)));

    $viewer = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id,
        'user_id' => $viewer->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($viewer, $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
    ]);

    $this->actingAs($viewer)
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leave_request.can_reassign_current_approval', false)
            ->where('reassignment_approver_candidates', []));
});

test('http reassignment endpoint updates only the current pending step', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $context['replacement']['employee']->id,
            'expected_approver_employee_id' => $context['step2']['employee']->id,
            'reassignment_reason' => 'HTTP recovery',
        ])
        ->assertRedirect(route('attendance.leave-requests.show', $leaveRequest));

    $pending = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->where('sequence', 2)
        ->firstOrFail();

    expect((int) $pending->approver_employee_id)->toBe((int) $context['replacement']['employee']->id)
        ->and($pending->status)->toBe(LeaveRequestApprovalStatus::Pending);
});
