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
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveRequestApprovalReassignment;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\CancelLeaveRequestWorkflow;
use App\Support\Attendance\Actions\DeleteLeaveRequest;
use App\Support\Attendance\Actions\ReassignLeaveRequestApproval;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAuthorization;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

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
    $suffix = fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => 'R'.$suffix, 'name' => 'Reassignland '.$suffix, 'dial_code' => '+971', 'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'R'.$suffix, 'name' => 'Reassign Currency '.$suffix, 'symbol' => 'R$', 'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Reassign Co '.$suffix,
        'slug' => 'ra-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $step1 = makeActionableApprover($company, ['name' => 'Mohamed', 'work_email' => "mohamed-ra-{$suffix}@example.com"]);
    $step2 = makeActionableApprover($company, ['name' => 'Rima', 'work_email' => "rima-ra-{$suffix}@example.com"]);
    $step3 = makeActionableApprover($company, ['name' => 'Ahmed', 'work_email' => "ahmed-ra-{$suffix}@example.com"]);
    $replacement = makeActionableApprover($company, ['name' => 'Sara', 'work_email' => "sara-ra-{$suffix}@example.com"]);

    ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step1['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step2['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $step3['employee']->id, 'required' => true],
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => "requester-ra-{$suffix}@example.com",
    ]);
    $leaveType = LeaveType::factory()->for($company)->create(['status' => 'active', 'days_per_year' => 40]);
    $admin = User::factory()->create(['status' => 'active', 'name' => 'HR Admin']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    return compact('company', 'employee', 'leaveType', 'step1', 'step2', 'step3', 'replacement', 'admin');
}

function grantLeaveReassignmentPermissions(User $user, Company $company): void
{
    grantCompanyPermissions($user, $company, [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.reassign_approval',
    ], 'leave-reassign-'.$user->id);
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

/** @return array{pending_days: string, used_days: string, remaining_days: string} */
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

function currentRequiredPendingApproval(LeaveRequest $leaveRequest): LeaveRequestApproval
{
    $leaveRequest->loadMissing('approvals');
    $pending = $leaveRequest->approvals
        ->sortBy('sequence')
        ->first(fn (LeaveRequestApproval $a): bool => $a->is_required && $a->status === LeaveRequestApprovalStatus::Pending);

    expect($pending)->not->toBeNull();

    return $pending;
}

function reassignCurrentPending(array $context, LeaveRequest $leaveRequest, int $newEmployeeId, string $reason): LeaveRequest
{
    $pending = currentRequiredPendingApproval($leaveRequest);

    return app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: $newEmployeeId,
        reason: $reason,
        expectedApproverEmployeeId: (int) $pending->approver_employee_id,
        expectedApprovalId: (int) $pending->id,
    );
}

function expectReassignmentFails(
    array $context,
    LeaveRequest $leaveRequest,
    int $newEmployeeId,
    string $reason,
    ?int $expectedApproverEmployeeId = null,
    ?int $expectedApprovalId = null,
): void {
    $pending = currentRequiredPendingApproval($leaveRequest->fresh(['approvals']) ?? $leaveRequest);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest->fresh() ?? $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: $newEmployeeId,
        reason: $reason,
        expectedApproverEmployeeId: $expectedApproverEmployeeId ?? (int) $pending->approver_employee_id,
        expectedApprovalId: $expectedApprovalId ?? (int) $pending->id,
    ))->toThrow(ValidationException::class);
}

test('privileged reassignment preserves approvals balances history activity and authorization', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));
    $beforeBalances = balanceSnapshotFor($leaveRequest);
    $approved = $leaveRequest->approvals->firstWhere('sequence', 1);
    $waiting = $leaveRequest->approvals->firstWhere('sequence', 3);
    $pending = currentRequiredPendingApproval($leaveRequest);
    $policy = collect($pending->only([
        'policy_id', 'policy_name', 'policy_step_id', 'policy_step_label',
        'approver_type', 'source_department_id', 'sequence',
    ]));

    $fresh = reassignCurrentPending($context, $leaveRequest, (int) $context['replacement']['employee']->id, 'Rima left company.')
        ->load('approvals');
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
        ->and(collect($step2->only($policy->keys()->all())))->toEqual($policy)
        ->and($step2->is_required)->toBeTrue()
        ->and($step3->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and((int) $step3->approver_employee_id)->toBe((int) $waiting->approver_employee_id)
        ->and(balanceSnapshotFor($fresh))->toBe($beforeBalances);

    $history = LeaveRequestApprovalReassignment::query()->where('leave_request_id', $fresh->id)->sole();
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
        ->sole();
    expect(Activity::query()
        ->where('subject_type', LeaveRequest::class)
        ->where('subject_id', $fresh->id)
        ->where('description', 'Leave approval reassigned')
        ->count())->toBe(1)
        ->and($activity->properties['from_approver_name'])->toBe('Rima')
        ->and($activity->properties['to_approver_name'])->toBe('Sara')
        ->and($activity->properties['reason'])->toBe('Rima left company.')
        ->and((int) $activity->causer_id)->toBe((int) $context['admin']->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($context['company']->id);
    $auth = app(LeaveRequestAuthorization::class);
    expect($auth->canApproveCurrentStep($fresh, $context['replacement']['user'], (int) $context['company']->id))->toBeTrue()
        ->and($auth->canApproveCurrentStep($fresh, $context['step2']['user'], (int) $context['company']->id))->toBeFalse();
});

test('reason required current-approver no-op and ineligible replacements are rejected', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));
    $pending = currentRequiredPendingApproval($leaveRequest);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: '   ',
        expectedApproverEmployeeId: (int) $pending->approver_employee_id,
        expectedApprovalId: (int) $pending->id,
    ))->toThrow(ValidationException::class);

    expectReassignmentFails($context, $leaveRequest, (int) $context['step2']['employee']->id, 'No-op');
    expect(LeaveRequestApprovalReassignment::query()->count())->toBe(0);

    expectReassignmentFails($context, $leaveRequest, (int) $context['employee']->id, 'Requester');
    expectReassignmentFails($context, $leaveRequest, (int) $context['step1']['employee']->id, 'Duplicate required');

    $inactiveEmployee = makeActionableApprover($context['company'], ['name' => 'Inactive Emp']);
    $inactiveEmployee['employee']->forceFill(['status' => 'inactive'])->save();
    expectReassignmentFails($context, $leaveRequest, (int) $inactiveEmployee['employee']->id, 'Inactive employee');

    $noUser = Employee::factory()->forCompany($context['company'])->create(['status' => 'active', 'user_id' => null, 'name' => 'No User']);
    expectReassignmentFails($context, $leaveRequest, (int) $noUser->id, 'No linked user');

    $inactiveUserPair = makeActionableApprover($context['company'], ['name' => 'Inactive User Emp']);
    $inactiveUserPair['user']->forceFill(['status' => 'inactive'])->save();
    expectReassignmentFails($context, $leaveRequest, (int) $inactiveUserPair['employee']->id, 'Inactive linked user');

    $noMembership = makeActionableApprover($context['company'], ['name' => 'No Membership']);
    DB::table('company_user')->where('company_id', $context['company']->id)->where('user_id', $noMembership['user']->id)
        ->update(['status' => 'inactive']);
    expectReassignmentFails($context, $leaveRequest, (int) $noMembership['employee']->id, 'No active membership');

    $missingApprove = makeActionableApprover($context['company'], ['name' => 'View Only']);
    grantCompanyPermissions($missingApprove['user'], $context['company'], ['attendance.leave-requests.view'], 'view-only-'.$missingApprove['user']->id);
    expectReassignmentFails($context, $leaveRequest, (int) $missingApprove['employee']->id, 'Missing approve');

    $missingView = makeActionableApprover($context['company'], ['name' => 'Approve Only']);
    grantCompanyPermissions($missingView['user'], $context['company'], ['attendance.leave-requests.approve'], 'approve-only-'.$missingView['user']->id);
    expectReassignmentFails($context, $leaveRequest, (int) $missingView['employee']->id, 'Missing view');
});

test('cross-company missing permission and terminal requests cannot reassign', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));
    $pending = currentRequiredPendingApproval($leaveRequest);

    $other = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($other['admin'], $other['company']);
    expectReassignmentFails($context, $leaveRequest, (int) $other['replacement']['employee']->id, 'Cross company employee');

    $this->actingAs($other['admin'])
        ->withSession(['current_company_id' => $other['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $other['replacement']['employee']->id,
            'expected_approver_employee_id' => $context['step2']['employee']->id,
            'expected_approval_id' => $pending->id,
            'reassignment_reason' => 'Cross company request',
        ])
        ->assertNotFound();

    $forbidden = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id, 'user_id' => $forbidden->id, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    grantCompanyPermissions($forbidden, $context['company'], [
        'attendance.leave-requests.view', 'attendance.leave-requests.view_all', 'attendance.leave-requests.approve',
    ], 'leave-no-reassign-'.$forbidden->id);

    $this->actingAs($forbidden)
        ->withSession(['current_company_id' => $context['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $context['replacement']['employee']->id,
            'expected_approver_employee_id' => $pending->approver_employee_id,
            'expected_approval_id' => $pending->id,
            'reassignment_reason' => 'Should fail',
        ])
        ->assertForbidden();

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
    app(RejectLeaveRequestStep::class)->handle($rejected, $context['step2']['user'], (int) $context['company']->id, 'No');
    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $rejected->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Rejected',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);

    $cancelled = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-06-01', '2026-06-02'));
    app(CancelLeaveRequestWorkflow::class)->handle($cancelled, $context['admin'], (int) $context['company']->id, 'Cancel');
    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $cancelled->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Cancelled',
        expectedApproverEmployeeId: (int) $context['step2']['employee']->id,
    ))->toThrow(ValidationException::class);
});

test('stale concurrent and rebuild tokens reject while eligibility and inactive current remain recoverable', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);

    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));
    $pending = currentRequiredPendingApproval($leaveRequest);
    app(ApproveLeaveRequestStep::class)->handle($leaveRequest, $context['step2']['user'], (int) $context['company']->id, 'Rima approved first');
    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $leaveRequest->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Stale after concurrent approve',
        expectedApproverEmployeeId: (int) $pending->approver_employee_id,
        expectedApprovalId: (int) $pending->id,
    ))->toThrow(ValidationException::class);
    expect(LeaveRequestApprovalReassignment::query()->count())->toBe(0);

    $rebuildable = submitMultiStepLeaveForReassignment($context, '2026-03-09', '2026-03-10');
    $oldStep1Id = (int) currentRequiredPendingApproval($rebuildable)->id;
    app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $rebuildable,
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-03-16',
            'end_date' => '2026-03-17',
            'reason' => 'Rebuild approvals',
        ],
        actor: $context['admin'],
    );
    $rebuilt = $rebuildable->fresh(['approvals']);
    expect((int) currentRequiredPendingApproval($rebuilt)->id)->not->toBe($oldStep1Id);
    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $rebuilt,
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $context['replacement']['employee']->id,
        reason: 'Stale approval id after rebuild',
        expectedApproverEmployeeId: (int) $context['step1']['employee']->id,
        expectedApprovalId: $oldStep1Id,
    ))->toThrow(ValidationException::class);

    $second = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-03-23', '2026-03-24'));
    $secondPending = currentRequiredPendingApproval($second);
    reassignCurrentPending($context, $second, (int) $context['replacement']['employee']->id, 'Admin A reassigned first');
    $otherReplacement = makeActionableApprover($context['company'], ['name' => 'Other Admin Target']);
    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $second->fresh(),
        companyId: (int) $context['company']->id,
        actor: $context['admin'],
        newApproverEmployeeId: (int) $otherReplacement['employee']->id,
        reason: 'Admin B stale dialog',
        expectedApproverEmployeeId: (int) $secondPending->approver_employee_id,
        expectedApprovalId: (int) $secondPending->id,
    ))->toThrow(ValidationException::class);
    expect(LeaveRequestApprovalReassignment::query()->where('leave_request_id', $second->id)->count())->toBe(1);

    $eligibility = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-03-30', '2026-03-31'));
    $context['replacement']['user']->forceFill(['status' => 'inactive'])->save();
    expectReassignmentFails($context, $eligibility, (int) $context['replacement']['employee']->id, 'Became ineligible');
    $context['replacement']['user']->forceFill(['status' => 'active'])->save();
    $context['step2']['employee']->forceFill(['status' => 'inactive'])->save();
    $recovered = reassignCurrentPending($context, $eligibility->fresh(['approvals']), (int) $context['replacement']['employee']->id, 'Recover inactive Rima');
    expect((int) currentRequiredPendingApproval($recovered)->approver_employee_id)->toBe((int) $context['replacement']['employee']->id);
});

test('emails queue when enabled and stay quiet when disabled or failed', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $this->seed(EmailTemplatesSeeder::class);
    EmailTemplate::query()->where('slug', 'leave_request_approver_action_required')->update(['enabled' => true]);

    Mail::fake();
    reassignCurrentPending(
        $context,
        advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context)),
        (int) $context['replacement']['employee']->id,
        'Notify Sara',
    );
    Mail::assertQueued(LeaveRequestSubmittedMail::class);

    CompanyLeaveApprovalSetting::query()->updateOrCreate(
        ['company_id' => $context['company']->id],
        ['email_notifications_enabled' => false, 'notify_next_approver' => true],
    );
    Mail::fake();
    reassignCurrentPending(
        $context,
        advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-07-06', '2026-07-07')),
        (int) $context['replacement']['employee']->id,
        'Notifications off',
    );
    Mail::assertNothingQueued();

    Mail::fake();
    expectReassignmentFails(
        $context,
        advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-07-13', '2026-07-14')),
        (int) $context['step2']['employee']->id,
        'No-op should not mail',
    );
    Mail::assertNothingQueued();
});

test('detail page candidates http put balances history rebuild soft-delete and fyi duplicates', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    $leaveRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context));
    $pending = currentRequiredPendingApproval($leaveRequest);
    $balanceCountBefore = LeaveBalance::query()->where('company_id', $context['company']->id)->count();

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leave_request.can_reassign_current_approval', true)
            ->has('reassignment_approver_candidates')
            ->where('reassignment_approver_candidates', fn ($candidates) => collect($candidates)
                ->contains(fn ($c) => (int) $c['id'] === (int) $context['replacement']['employee']->id)));

    $viewer = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id, 'user_id' => $viewer->id, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    grantCompanyPermissions($viewer, $context['company'], [
        'attendance.leave-requests.view', 'attendance.leave-requests.view_all',
    ], 'leave-viewer-'.$viewer->id);

    $this->actingAs($viewer)
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.show', $leaveRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leave_request.can_reassign_current_approval', false)
            ->where('reassignment_approver_candidates', []));

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->put(route('attendance.leave-requests.reassign-approval', $leaveRequest), [
            'new_approver_employee_id' => $context['replacement']['employee']->id,
            'expected_approver_employee_id' => $pending->approver_employee_id,
            'expected_approval_id' => $pending->id,
            'reassignment_reason' => 'HTTP recovery',
        ])
        ->assertRedirect(route('attendance.leave-requests.show', $leaveRequest));

    expect((int) LeaveRequestApproval::query()->where('leave_request_id', $leaveRequest->id)->where('sequence', 2)->value('approver_employee_id'))
        ->toBe((int) $context['replacement']['employee']->id)
        ->and(LeaveBalance::query()->where('company_id', $context['company']->id)->count())->toBe($balanceCountBefore);

    $missingBalanceRequest = advanceFirstStepForReassignment($context, submitMultiStepLeaveForReassignment($context, '2026-08-03', '2026-08-04'));
    LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->forceDelete();
    expect(fn () => reassignCurrentPending($context, $missingBalanceRequest, (int) $context['replacement']['employee']->id, 'Missing balance'))
        ->toThrow(ValidationException::class);
    expect(LeaveRequestApprovalReassignment::query()->where('leave_request_id', $missingBalanceRequest->id)->count())->toBe(0);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2026);
    app(LeaveBalanceManager::class)->syncEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2026);

    $editable = submitMultiStepLeaveForReassignment($context, '2026-09-01', '2026-09-02');
    reassignCurrentPending($context, $editable, (int) $context['replacement']['employee']->id, 'First-step reassignment before edit');
    $historyBeforeRebuild = LeaveRequestApprovalReassignment::query()
        ->where('leave_request_id', $editable->id)
        ->firstOrFail();
    $priorApprovalId = (int) $historyBeforeRebuild->leave_request_approval_id;
    expect($priorApprovalId)->toBeGreaterThan(0)
        ->and($historyBeforeRebuild->sequence)->toBe(1)
        ->and($historyBeforeRebuild->reason)->toBe('First-step reassignment before edit')
        ->and($historyBeforeRebuild->to_approver_name)->toBe('Sara');

    $updated = app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $editable->fresh(),
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-09-07',
            'end_date' => '2026-09-08',
            'reason' => 'Updated after first-step reassignment',
        ],
        actor: $context['admin'],
    );
    $historyAfterRebuild = LeaveRequestApprovalReassignment::query()
        ->where('leave_request_id', $updated->id)
        ->firstOrFail();
    expect(LeaveRequestApproval::query()->whereKey($priorApprovalId)->exists())->toBeFalse()
        ->and($historyAfterRebuild->leave_request_approval_id)->toBeNull()
        ->and((int) $historyAfterRebuild->sequence)->toBe(1)
        ->and($historyAfterRebuild->reason)->toBe('First-step reassignment before edit')
        ->and($historyAfterRebuild->from_approver_name)->toBe('Mohamed')
        ->and($historyAfterRebuild->to_approver_name)->toBe('Sara')
        ->and(LeaveRequestApprovalReassignment::query()->where('leave_request_id', $updated->id)->count())->toBe(1)
        ->and((int) currentRequiredPendingApproval($updated)->approver_employee_id)->toBe((int) $context['step1']['employee']->id);

    $deletable = submitMultiStepLeaveForReassignment($context, '2026-09-14', '2026-09-15');
    reassignCurrentPending($context, $deletable, (int) $context['replacement']['employee']->id, 'History before soft delete');
    app(DeleteLeaveRequest::class)->handle($deletable->fresh(), (int) $context['company']->id);
    expect(LeaveRequest::query()->find($deletable->id))->toBeNull()
        ->and(LeaveRequest::withTrashed()->find($deletable->id))->not->toBeNull()
        ->and(LeaveRequestApprovalReassignment::query()->where('leave_request_id', $deletable->id)->count())->toBe(1);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $context['step2']['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $context['replacement']['employee']->id, 'required' => false],
    ]);
    $fyiRequest = submitMultiStepLeaveForReassignment($context, '2026-10-05', '2026-10-06');
    expect((int) currentRequiredPendingApproval($fyiRequest)->approver_employee_id)->toBe((int) $context['step2']['employee']->id);
    expectReassignmentFails($context, $fyiRequest, (int) $context['replacement']['employee']->id, 'FYI duplicate Sara');

    $this->actingAs($context['admin'])
        ->withSession(['current_company_id' => $context['company']->id])
        ->get(route('attendance.leave-requests.show', $fyiRequest))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('leave_request.can_reassign_current_approval', true)
            ->where('reassignment_approver_candidates', fn ($candidates) => collect($candidates)
                ->doesntContain(fn ($c) => (int) $c['id'] === (int) $context['replacement']['employee']->id)
                && collect($candidates)->contains(fn ($c) => (int) $c['id'] === (int) $context['step1']['employee']->id)));
});

test('reassignment rejects corrupted pending ledger without mutating approver history or balances', function () {
    $context = makeLeaveReassignmentContext();
    grantLeaveReassignmentPermissions($context['admin'], $context['company']);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2027);

    $healthy = submitMultiStepLeaveForReassignment($context, '2026-11-02', '2026-11-03');
    $healthyPending = currentRequiredPendingApproval($healthy);
    reassignCurrentPending($context, $healthy, (int) $context['replacement']['employee']->id, 'Healthy pending ledger');
    expect((int) $healthyPending->fresh()->approver_employee_id)->toBe((int) $context['replacement']['employee']->id);

    $zeroCorrupt = submitMultiStepLeaveForReassignment($context, '2026-11-09', '2026-11-10');
    $zeroPending = currentRequiredPendingApproval($zeroCorrupt);
    $zeroBalance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $zeroBefore = [
        'pending_days' => (string) $zeroBalance->pending_days,
        'used_days' => (string) $zeroBalance->used_days,
        'entitled_days' => (string) $zeroBalance->entitled_days,
        'carried_days' => (string) $zeroBalance->carried_days,
    ];
    $historyBefore = LeaveRequestApprovalReassignment::query()->where('company_id', $context['company']->id)->count();
    $zeroBalance->forceFill(['pending_days' => 0])->save();

    expectReassignmentFails($context, $zeroCorrupt, (int) $context['replacement']['employee']->id, 'Zero pending corruption');
    expect((int) $zeroPending->fresh()->approver_employee_id)->toBe((int) $context['step1']['employee']->id)
        ->and(LeaveRequestApprovalReassignment::query()->where('company_id', $context['company']->id)->count())->toBe($historyBefore)
        ->and((string) $zeroBalance->fresh()->pending_days)->toBe('0.00')
        ->and((string) $zeroBalance->fresh()->used_days)->toBe($zeroBefore['used_days'])
        ->and((string) $zeroBalance->fresh()->entitled_days)->toBe($zeroBefore['entitled_days'])
        ->and((string) $zeroBalance->fresh()->carried_days)->toBe($zeroBefore['carried_days']);

    $zeroBalance->forceFill(['pending_days' => $zeroBefore['pending_days']])->save();

    $highCorrupt = submitMultiStepLeaveForReassignment($context, '2026-11-16', '2026-11-17');
    $highBalance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $expectedPending = (float) $highBalance->pending_days;
    $highBalance->forceFill(['pending_days' => $expectedPending + 5])->save();
    expectReassignmentFails($context, $highCorrupt, (int) $context['replacement']['employee']->id, 'High pending corruption');
    $highBalance->forceFill(['pending_days' => $expectedPending])->save();

    $lowCorrupt = submitMultiStepLeaveForReassignment($context, '2026-11-23', '2026-11-24');
    $lowBalance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $lowExpected = (float) $lowBalance->pending_days;
    $lowBalance->forceFill(['pending_days' => max(0, $lowExpected - 1)])->save();
    expectReassignmentFails($context, $lowCorrupt, (int) $context['replacement']['employee']->id, 'Low pending corruption');
    $lowBalance->forceFill(['pending_days' => $lowExpected])->save();

    $sharedA = submitMultiStepLeaveForReassignment($context, '2026-12-01', '2026-12-02');
    $sharedB = submitMultiStepLeaveForReassignment($context, '2026-12-07', '2026-12-08');
    $sharedBalance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $sharedExpected = (float) $sharedBalance->pending_days;
    reassignCurrentPending($context, $sharedA, (int) $context['replacement']['employee']->id, 'Shared aggregate healthy');
    $sharedBalance->forceFill(['pending_days' => $sharedExpected - 2])->save();
    expectReassignmentFails($context, $sharedB, (int) $context['replacement']['employee']->id, 'Shared aggregate mismatch');
    $sharedBalance->forceFill(['pending_days' => $sharedExpected])->save();

    $crossYear = submitMultiStepLeaveForReassignment($context, '2026-12-30', '2027-01-02');
    $balance2026 = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();
    $balance2027 = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2027)
        ->firstOrFail();
    $crossPending2026 = (float) $balance2026->pending_days;
    $crossPending2027 = (float) $balance2027->pending_days;
    expect($crossPending2026)->toBeGreaterThan(0.0)
        ->and($crossPending2027)->toBeGreaterThan(0.0);

    $balance2027->forceFill(['pending_days' => 0])->save();
    expectReassignmentFails($context, $crossYear, (int) $context['replacement']['employee']->id, 'Cross-year pending corruption');
    expect((int) currentRequiredPendingApproval($crossYear)->approver_employee_id)->toBe((int) $context['step1']['employee']->id)
        ->and((float) $balance2026->fresh()->pending_days)->toBe($crossPending2026)
        ->and((float) $balance2027->fresh()->pending_days)->toBe(0.0);

    $balance2027->forceFill(['pending_days' => $crossPending2027])->save();
    $crossYear = reassignCurrentPending($context, $crossYear->fresh(['approvals']) ?? $crossYear, (int) $context['replacement']['employee']->id, 'Cross-year healthy');
    expect((int) currentRequiredPendingApproval($crossYear)->approver_employee_id)->toBe((int) $context['replacement']['employee']->id);
});
