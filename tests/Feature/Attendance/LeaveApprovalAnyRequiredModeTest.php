<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\ReassignLeaveRequestApproval;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;
use App\Support\Attendance\LeaveApprovalNeedsActionCounter;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Attendance\LeaveRequestAuthorization;
use App\Support\Attendance\LeaveRequestVisibility;
use App\Support\Attendance\SyncLeaveApprovalPolicyToPendingRequests;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     rima: array{employee: Employee, user: User},
 *     maher: array{employee: Employee, user: User},
 *     adam: array{employee: Employee, user: User},
 *     policy: LeaveApprovalPolicy,
 *     admin: User
 * }
 */
function makeAnyRequiredModeContext(LeaveApprovalMode $mode = LeaveApprovalMode::AnyRequired): array
{
    $suffix = fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => 'AR'.$suffix,
        'name' => 'Any Requiredland '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AR'.$suffix,
        'name' => 'Any Required Currency '.$suffix,
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Any Required Co '.$suffix,
        'slug' => 'ar-'.$suffix,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rima = makeActionableApprover($company, [
        'name' => 'Rima',
        'work_email' => "rima-ar-{$suffix}@example.com",
    ]);
    $maher = makeActionableApprover($company, [
        'name' => 'Maher',
        'work_email' => "maher-ar-{$suffix}@example.com",
    ]);
    $adam = makeActionableApprover($company, [
        'name' => 'Adam',
        'work_email' => "adam-ar-{$suffix}@example.com",
    ]);

    $policy = ensureDefaultLeaveApprovalPolicy($company, [
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $rima['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $maher['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $adam['employee']->id, 'required' => false],
    ], $mode);

    $employee = createAttendanceLeaveEmployee($company, [
        'status' => 'active',
        'work_email' => "requester-ar-{$suffix}@example.com",
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 40,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $admin = User::factory()->create(['status' => 'active', 'name' => 'AR Admin']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $admin->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('company', 'employee', 'leaveType', 'rima', 'maher', 'adam', 'policy', 'admin');
}

function submitAnyRequiredLeave(array $context, bool $notify = false): LeaveRequest
{
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-02',
            'reason' => 'Any required flow',
        ],
        notify: $notify,
    );
}

test('policy create and update accept approval modes and reject invalid values', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $user = $context['admin'];
    grantCompanyPermissions($user, $context['company'], [
        'attendance.leave-approval-policies.view',
        'attendance.leave-approval-policies.create',
        'attendance.leave-approval-policies.update',
    ]);
    $this->actingAs($user);

    $this->withSession(['current_company_id' => $context['company']->id])
        ->post('/attendance/leave-approval-policies', [
            'name' => 'Any mode policy',
            'description' => null,
            'is_default' => false,
            'status' => 'active',
            'approval_mode' => LeaveApprovalMode::AnyRequired->value,
            'steps' => [
                [
                    'approver_type' => LeaveApprovalApproverType::SpecificEmployee->value,
                    'approver_employee_id' => $context['rima']['employee']->id,
                    'is_required' => true,
                ],
            ],
        ])
        ->assertRedirect();

    $policy = LeaveApprovalPolicy::query()
        ->where('company_id', $context['company']->id)
        ->where('name', 'Any mode policy')
        ->firstOrFail();

    expect($policy->approvalMode())->toBe(LeaveApprovalMode::AnyRequired);

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => null,
        'is_default' => false,
        'status' => 'active',
        'approval_mode' => 'not_a_real_mode',
        'steps' => [
            [
                'approver_type' => LeaveApprovalApproverType::SpecificEmployee->value,
                'approver_employee_id' => $context['rima']['employee']->id,
                'is_required' => true,
            ],
        ],
    ])->assertSessionHasErrors('approval_mode');

    $this->put("/attendance/leave-approval-policies/{$policy->id}", [
        'name' => $policy->name,
        'description' => null,
        'is_default' => false,
        'status' => 'active',
        'approval_mode' => LeaveApprovalMode::AllRequired->value,
        'steps' => [
            [
                'approver_type' => LeaveApprovalApproverType::SpecificEmployee->value,
                'approver_employee_id' => $context['rima']['employee']->id,
                'is_required' => true,
            ],
        ],
    ])->assertRedirect();

    expect($policy->fresh()->approvalMode())->toBe(LeaveApprovalMode::AllRequired);
});

test('existing policies and requests default to all required', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $leaveRequest = submitAnyRequiredLeave($context);

    expect($context['policy']->approvalMode())->toBe(LeaveApprovalMode::AllRequired)
        ->and($leaveRequest->approvalMode())->toBe(LeaveApprovalMode::AllRequired);

    $approvals = $leaveRequest->approvals()->orderBy('sequence')->get();
    expect($approvals)->toHaveCount(3)
        ->and($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and($approvals[2]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and((bool) $approvals[2]->is_required)->toBeFalse();
});

test('any required snapshot makes all required steps pending and notify only skipped', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context, notify: true);

    expect($leaveRequest->approvalMode())->toBe(LeaveApprovalMode::AnyRequired);

    $approvals = $leaveRequest->approvals()->orderBy('sequence')->get();
    expect($approvals)->toHaveCount(3)
        ->and($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[2]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and((bool) $approvals[0]->is_required)->toBeTrue()
        ->and((bool) $approvals[1]->is_required)->toBeTrue()
        ->and((bool) $approvals[2]->is_required)->toBeFalse();

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 3);
    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['rima']['employee']->work_email);
    });
    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['maher']['employee']->work_email);
    });
    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['adam']['employee']->work_email);
    });
});

test('any required first approval finalizes request and cancels other pending required steps', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);

    $approved = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['rima']['user'],
        (int) $context['company']->id,
        'Looks good',
    );

    expect($approved->status)->toBe('approved')
        ->and((int) $approved->approved_by)->toBe((int) $context['rima']['user']->id);

    $approvals = $approved->approvals()->orderBy('sequence')->get();
    expect($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Cancelled)
        ->and($approvals[2]->status)->toBe(LeaveRequestApprovalStatus::Skipped);

    $balance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0)
        ->and((float) $balance->used_days)->toBe(2.0);

    Mail::assertQueued(LeaveRequestDecidedMail::class, 1);
});

test('any required first rejection rejects request and cancels sibling pending steps', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);

    $rejected = app(RejectLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['maher']['user'],
        (int) $context['company']->id,
        'Not enough coverage',
    );

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->rejection_reason)->toBe('Not enough coverage');

    $approvals = $rejected->approvals()->orderBy('sequence')->get();
    expect($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Cancelled)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Rejected)
        ->and($approvals[2]->status)->toBe(LeaveRequestApprovalStatus::Skipped);

    $balance = LeaveBalance::query()
        ->where('company_id', $context['company']->id)
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->pending_days)->toBe(0.0)
        ->and((float) $balance->used_days)->toBe(0.0);

    Mail::assertQueued(LeaveRequestDecidedMail::class, 1);
});

test('any required parallel approvers see needs action and can approve their own step', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);
    $visibility = app(LeaveRequestVisibility::class);
    $authorization = app(LeaveRequestAuthorization::class);
    $counter = app(LeaveApprovalNeedsActionCounter::class);
    $companyId = (int) $context['company']->id;

    expect($visibility->canApproveCurrentStep($leaveRequest, $context['rima']['user'], $companyId))->toBeTrue()
        ->and($visibility->canApproveCurrentStep($leaveRequest, $context['maher']['user'], $companyId))->toBeTrue()
        ->and($visibility->canApproveCurrentStep($leaveRequest, $context['adam']['user'], $companyId))->toBeFalse()
        ->and($authorization->canApproveCurrentStep($leaveRequest->load('approvals'), $context['adam']['user'], $companyId))->toBeFalse()
        ->and($counter->count($context['rima']['user'], $companyId))->toBe(1)
        ->and($counter->count($context['maher']['user'], $companyId))->toBe(1)
        ->and($counter->count($context['adam']['user'], $companyId))->toBe(0);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['rima']['user'],
        $companyId,
    );

    $fresh = $leaveRequest->fresh();
    expect($counter->count($context['rima']['user'], $companyId))->toBe(0)
        ->and($counter->count($context['maher']['user'], $companyId))->toBe(0)
        ->and($visibility->canApproveCurrentStep($fresh, $context['maher']['user'], $companyId))->toBeFalse();
});

test('view_all approve alone cannot act on any required request', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);

    $outsider = User::factory()->create(['status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $context['company']->id,
        'user_id' => $outsider->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    grantCompanyPermissions($outsider, $context['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.approve',
    ]);

    expect(app(LeaveRequestVisibility::class)->canApproveCurrentStep(
        $leaveRequest,
        $outsider,
        (int) $context['company']->id,
    ))->toBeFalse();

    expect(fn () => app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $outsider,
        (int) $context['company']->id,
    ))->toThrow(HttpException::class);
});

test('second decision after any required terminal decision fails safely', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['rima']['user'],
        (int) $context['company']->id,
    );

    expect(fn () => app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['maher']['user'],
        (int) $context['company']->id,
    ))->toThrow(ValidationException::class);

    $fresh = $leaveRequest->fresh(['approvals']);
    expect($fresh->status)->toBe('approved')
        ->and((int) $fresh->approved_by)->toBe((int) $context['rima']['user']->id)
        ->and($fresh->approvals->where('status', LeaveRequestApprovalStatus::Approved)->count())->toBe(1);

    $balance = LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->firstOrFail();

    expect((float) $balance->used_days)->toBe(2.0);
});

test('edit before action rebuilds any required snapshot with all required pending', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);

    $updated = app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-10-05',
            'end_date' => '2026-10-06',
            'reason' => 'Updated before action',
        ],
        actor: $context['admin'],
    );

    expect($updated->approvalMode())->toBe(LeaveApprovalMode::AnyRequired);

    $approvals = $updated->approvals()->orderBy('sequence')->get();
    expect($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[2]->status)->toBe(LeaveRequestApprovalStatus::Skipped);
});

test('sync pending snapshots new any required mode onto eligible requests', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $leaveRequest = submitAnyRequiredLeave($context);

    expect($leaveRequest->approvalMode())->toBe(LeaveApprovalMode::AllRequired)
        ->and($leaveRequest->approvals()->where('status', LeaveRequestApprovalStatus::Pending)->count())->toBe(1);

    $context['policy']->forceFill([
        'approval_mode' => LeaveApprovalMode::AnyRequired,
    ])->save();

    // Policy change alone must not rewrite the in-flight snapshot.
    expect($leaveRequest->fresh()->approvalMode())->toBe(LeaveApprovalMode::AllRequired);

    $result = app(SyncLeaveApprovalPolicyToPendingRequests::class)->handle(
        $context['policy']->fresh(),
        (int) $context['company']->id,
        $context['admin'],
    );

    expect($result->synchronizedCount)->toBe(1);

    $fresh = $leaveRequest->fresh(['approvals']);
    expect($fresh->approvalMode())->toBe(LeaveApprovalMode::AnyRequired)
        ->and($fresh->approvals()->where('status', LeaveRequestApprovalStatus::Pending)->count())->toBe(2)
        ->and($fresh->approvals()->where('status', LeaveRequestApprovalStatus::Waiting)->count())->toBe(0);
});

test('sync pending refuses requests where human approval already started', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $leaveRequest = submitAnyRequiredLeave($context);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['rima']['user'],
        (int) $context['company']->id,
    );

    // Still pending after first sequential approval.
    expect($leaveRequest->fresh()->status)->toBe('pending');

    $context['policy']->forceFill([
        'approval_mode' => LeaveApprovalMode::AnyRequired,
    ])->save();

    $result = app(SyncLeaveApprovalPolicyToPendingRequests::class)->handle(
        $context['policy']->fresh(),
        (int) $context['company']->id,
        $context['admin'],
    );

    expect($result->synchronizedCount)->toBe(0)
        ->and($leaveRequest->fresh()->approvalMode())->toBe(LeaveApprovalMode::AllRequired);
});

test('reassignment remains available for all required and blocked for any required', function () {
    $allContext = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $allRequest = submitAnyRequiredLeave($allContext);
    grantCompanyPermissions($allContext['admin'], $allContext['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.reassign_approval',
    ]);

    expect(app(LeaveRequestAuthorization::class)->canReassignCurrentApproval(
        $allRequest,
        $allContext['admin'],
        (int) $allContext['company']->id,
    ))->toBeTrue();

    $anyContext = makeAnyRequiredModeContext(LeaveApprovalMode::AnyRequired);
    $anyRequest = submitAnyRequiredLeave($anyContext);
    grantCompanyPermissions($anyContext['admin'], $anyContext['company'], [
        'attendance.leave-requests.view',
        'attendance.leave-requests.view_all',
        'attendance.leave-requests.reassign_approval',
    ]);

    expect(app(LeaveRequestAuthorization::class)->canReassignCurrentApproval(
        $anyRequest,
        $anyContext['admin'],
        (int) $anyContext['company']->id,
    ))->toBeFalse();

    $replacement = makeActionableApprover($anyContext['company'], [
        'name' => 'Replacement',
        'work_email' => 'replacement-ar@example.com',
    ]);

    expect(fn () => app(ReassignLeaveRequestApproval::class)->handle(
        leaveRequest: $anyRequest,
        companyId: (int) $anyContext['company']->id,
        actor: $anyContext['admin'],
        newApproverEmployeeId: (int) $replacement['employee']->id,
        reason: 'Trying parallel reassignment',
    ))->toThrow(ValidationException::class);
});

test('policy index serializes approval mode for inertia', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AnyRequired);
    grantCompanyPermissions($context['admin'], $context['company'], [
        'attendance.leave-approval-policies.view',
    ]);
    $this->actingAs($context['admin']);

    $this->withSession(['current_company_id' => $context['company']->id])
        ->get('/attendance/leave-approval-policies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('attendance/leave-approval-policies')
            ->has('policies', 1)
            ->where('policies.0.approval_mode', LeaveApprovalMode::AnyRequired->value)
            ->where('policies.0.approval_mode_label', LeaveApprovalMode::AnyRequired->label()));
});

test('requester cannot be resolved as a required approver in any required mode', function () {
    $context = makeAnyRequiredModeContext();

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $context['employee']->id, 'required' => true],
        ['type' => LeaveApprovalApproverType::SpecificEmployee, 'employee_id' => $context['maher']['employee']->id, 'required' => true],
    ], LeaveApprovalMode::AnyRequired);

    expect(fn () => submitAnyRequiredLeave($context))->toThrow(ValidationException::class);
});

test('preset recipients are not multiplied across parallel any required submissions', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    EmailTemplate::query()
        ->where('slug', 'leave_request_submitted')
        ->update([
            'to_preset' => 'preset-to@example.com',
            'cc_preset' => 'preset-cc@example.com',
        ]);

    $context = makeAnyRequiredModeContext();
    submitAnyRequiredLeave($context, notify: true);

    $queued = Mail::queued(LeaveRequestSubmittedMail::class);
    expect($queued)->toHaveCount(3);

    $mailsWithPresetCc = collect($queued)->filter(function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasCc('preset-to@example.com') || $mail->hasCc('preset-cc@example.com');
    });

    expect($mailsWithPresetCc)->toHaveCount(1);
});

test('parallel required approver emails exclude all sibling approvers from preset recipients', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext();
    $maherEmail = (string) $context['maher']['employee']->work_email;

    EmailTemplate::query()
        ->where('slug', 'leave_request_submitted')
        ->update([
            'to_preset' => strtoupper($maherEmail),
            'cc_preset' => 'extra-fyi@example.com',
        ]);

    submitAnyRequiredLeave($context, notify: true);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['rima']['employee']->work_email)
            && ! $mail->hasCc($context['maher']['employee']->work_email)
            && $mail->hasCc('extra-fyi@example.com');
    });

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['maher']['employee']->work_email)
            && ! $mail->hasCc($context['rima']['employee']->work_email);
    });

    $maherToCount = collect(Mail::queued(LeaveRequestSubmittedMail::class))
        ->filter(fn (LeaveRequestSubmittedMail $mail): bool => $mail->hasTo($context['maher']['employee']->work_email))
        ->count();

    expect($maherToCount)->toBe(1);
});

test('any required actionable emails include first-decision wording and notify only does not', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext();
    submitAnyRequiredLeave($context, notify: true);

    $note = 'Only one required approver needs to act. The first approval or rejection completes this request.';

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context, $note): bool {
        return $mail->hasTo($context['rima']['employee']->work_email)
            && str_contains((string) $mail->introMessage, $note);
    });

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context, $note): bool {
        return $mail->hasTo($context['maher']['employee']->work_email)
            && str_contains((string) $mail->introMessage, $note);
    });

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context, $note): bool {
        return $mail->hasTo($context['adam']['employee']->work_email)
            && ! str_contains((string) ($mail->introMessage ?? ''), $note);
    });
});

test('all required actionable emails do not include any required wording', function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();

    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    submitAnyRequiredLeave($context, notify: true);

    $note = 'Only one required approver needs to act. The first approval or rejection completes this request.';

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($note): bool {
        return ! str_contains((string) ($mail->introMessage ?? ''), $note);
    });
});

test('any required pending invariant rejects corrupted mixed required statuses', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);
    $invariant = app(AssertLeaveApprovalWorkflowInvariant::class);

    $approvals = $leaveRequest->approvals()->orderBy('sequence')->get();
    expect($invariant->forPendingRequest($leaveRequest, $approvals))->not->toBeNull();

    $firstRequired = $leaveRequest->approvals()->where('is_required', true)->orderBy('sequence')->firstOrFail();

    foreach ([
        LeaveRequestApprovalStatus::Approved,
        LeaveRequestApprovalStatus::Rejected,
        LeaveRequestApprovalStatus::Cancelled,
        LeaveRequestApprovalStatus::Waiting,
    ] as $corruptStatus) {
        $firstRequired->forceFill([
            'status' => $corruptStatus,
            'acted_at' => now(),
        ])->save();

        expect(fn () => $invariant->forPendingRequest(
            $leaveRequest->fresh(),
            $leaveRequest->fresh()->approvals()->orderBy('sequence')->get(),
        ))->toThrow(ValidationException::class);

        $firstRequired->forceFill([
            'status' => LeaveRequestApprovalStatus::Pending,
            'acted_at' => null,
        ])->save();
    }
});

test('any required terminal snapshots pass the tightened invariant', function () {
    $context = makeAnyRequiredModeContext();
    $leaveRequest = submitAnyRequiredLeave($context);
    $invariant = app(AssertLeaveApprovalWorkflowInvariant::class);

    $approved = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['rima']['user'],
        (int) $context['company']->id,
    );

    expect($approved->status)->toBe('approved');
    $invariant->forTerminalRequest($approved, $approved->approvals);

    $rejectedRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'reason' => 'Reject flow',
        ],
        notify: false,
    );

    $rejected = app(RejectLeaveRequestStep::class)->handle(
        $rejectedRequest,
        $context['maher']['user'],
        (int) $context['company']->id,
        'No',
    );

    expect($rejected->status)->toBe('rejected');
    $invariant->forTerminalRequest($rejected, $rejected->approvals);
});
