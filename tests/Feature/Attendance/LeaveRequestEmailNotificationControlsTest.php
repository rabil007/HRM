<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     manager: Employee,
 *     managerUser: User,
 *     departmentId: int
 * }
 */
function makeLeaveEmailNotificationControlFixtures(): array
{
    $user = User::factory()->create(['status' => 'active']);
    $country = Country::query()->create([
        'code' => 'EC'.fake()->unique()->numerify('##'),
        'name' => 'Email Control Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'EC'.fake()->unique()->numerify('##'),
        'name' => 'Email Control Currency',
        'symbol' => 'E$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Email Control Co',
        'slug' => 'ec-'.fake()->unique()->numerify('####'),
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

    $managed = makeManagedDepartment($company);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'department_id' => $managed['department']->id,
        'user_id' => $user->id,
        'work_email' => 'employee-control@example.com',
    ]);
    $leaveType = LeaveType::factory()->for($company)->create([
        'status' => 'active',
        'days_per_year' => 30,
    ]);
    app(LeaveBalanceManager::class)->ensureEmployeeYear((int) $company->id, (int) $employee->id, 2026);

    $managed['manager']->forceFill([
        'work_email' => 'dept-manager@example.com',
        'personal_email' => null,
    ])->save();

    return [
        'user' => $user,
        'company' => $company,
        'employee' => $employee,
        'leaveType' => $leaveType,
        'manager' => $managed['manager'],
        'managerUser' => $managed['managerUser'],
        'departmentId' => (int) $managed['department']->id,
    ];
}

function configureLeaveEmailNotificationSettings(Company $company, array $overrides = []): CompanyLeaveApprovalSetting
{
    $settings = CompanyLeaveApprovalSetting::forCompany($company->id);
    $settings->update(array_merge([
        'email_notifications_enabled' => true,
        'notify_on_submission' => true,
        'notify_on_update' => true,
        'notify_next_approver' => true,
        'notify_on_final_decision' => true,
        'copy_deciding_approver' => true,
    ], $overrides));

    return $settings->fresh();
}

function seedAllLeaveEmailTemplates(): void
{
    EmailTemplatesSeeder::seedLeaveRequestSubmittedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestUpdatedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestApproverActionRequiredTemplate();
    EmailTemplatesSeeder::seedLeaveRequestApprovedTemplate();
    EmailTemplatesSeeder::seedLeaveRequestRejectedTemplate();
}

test('master switch off suppresses all leave-request emails', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Approver',
        'work_email' => 'hr-control@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    configureLeaveEmailNotificationSettings($context['company'], [
        'email_notifications_enabled' => false,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Master off',
        ],
    );

    app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Updated while master off',
        ],
    );

    $afterFirst = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    app(ApproveLeaveRequestStep::class)->handle(
        $afterFirst,
        $hr['user'],
        (int) $context['company']->id,
    );

    Mail::assertNothingQueued();
});

test('submission switch off suppresses only submitted email', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company'], [
        'notify_on_submission' => false,
    ]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'No submit mail',
        ],
    );

    Mail::assertNothingQueued();

    $leaveRequest = LeaveRequest::query()->where('employee_id', $context['employee']->id)->firstOrFail();

    app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Update should still mail',
        ],
    );

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
});

test('update switch off suppresses only updated email', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company'], [
        'notify_on_update' => false,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Submit mails',
        ],
    );

    Mail::assertQueued(LeaveRequestSubmittedMail::class, 1);
    Mail::fake();

    app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'No update mail',
        ],
    );

    Mail::assertNothingQueued();
});

test('next-approver switch off suppresses only action-required email', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Approver',
        'work_email' => 'hr-next@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    configureLeaveEmailNotificationSettings($context['company'], [
        'notify_next_approver' => false,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Two step',
        ],
        notify: false,
    );

    Mail::fake();

    $afterFirst = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    expect($afterFirst->status)->toBe('pending')
        ->and(
            LeaveRequestApproval::query()
                ->where('leave_request_id', $afterFirst->id)
                ->where('sequence', 2)
                ->value('status')
        )->toBe(LeaveRequestApprovalStatus::Pending);

    Mail::assertNothingQueued();

    app(ApproveLeaveRequestStep::class)->handle(
        $afterFirst,
        $hr['user'],
        (int) $context['company']->id,
    );

    Mail::assertQueued(LeaveRequestDecidedMail::class, 1);
});

test('final-decision switch off suppresses approved and rejected decision emails', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company'], [
        'notify_on_final_decision' => false,
    ]);

    $approvedRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Approve path',
        ],
        notify: false,
    );

    Mail::fake();

    app(ApproveLeaveRequestStep::class)->handle(
        $approvedRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    Mail::assertNothingQueued();

    $rejectedRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Reject path',
        ],
        notify: false,
    );

    Mail::fake();

    app(RejectLeaveRequestStep::class)->handle(
        $rejectedRequest,
        $context['managerUser'],
        (int) $context['company']->id,
        'Coverage',
    );

    Mail::assertNothingQueued();
});

test('copy_deciding_approver false sends final decision to employee without deciding approver CC', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    $context['manager']->forceFill(['work_email' => 'deciding-approver@example.com'])->save();
    configureLeaveEmailNotificationSettings($context['company'], [
        'copy_deciding_approver' => false,
    ]);

    EmailTemplate::query()->where('slug', 'leave_request_approved')->update([
        'to_preset' => null,
        'cc_preset' => null,
        'enabled' => true,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'No CC',
        ],
        notify: false,
    );

    Mail::fake();

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail) {
        expect($mail->hasTo('employee-control@example.com'))->toBeTrue()
            ->and($mail->hasCc('deciding-approver@example.com'))->toBeFalse();

        return true;
    });
});

test('copy_deciding_approver true preserves the existing final-decision copy', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    $context['manager']->forceFill(['work_email' => 'deciding-approver@example.com'])->save();
    configureLeaveEmailNotificationSettings($context['company'], [
        'copy_deciding_approver' => true,
    ]);

    EmailTemplate::query()->where('slug', 'leave_request_approved')->update([
        'to_preset' => null,
        'cc_preset' => null,
        'enabled' => true,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'With CC',
        ],
        notify: false,
    );

    Mail::fake();

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail) {
        expect($mail->hasTo('employee-control@example.com'))->toBeTrue()
            ->and($mail->hasCc('deciding-approver@example.com'))->toBeTrue();

        return true;
    });
});

test('disabled EmailTemplate still suppresses the email even when company setting is enabled', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company']);

    EmailTemplate::query()->where('slug', 'leave_request_submitted')->update(['enabled' => false]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Template off',
        ],
    );

    Mail::assertNothingQueued();
});

test('missing recipient email safely skips sending', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company']);

    $context['manager']->forceFill([
        'work_email' => null,
        'personal_email' => null,
    ])->save();
    $context['managerUser']->forceFill(['email' => ''])->save();

    EmailTemplate::query()->where('slug', 'leave_request_submitted')->update([
        'to_preset' => null,
        'cc_preset' => null,
        'enabled' => true,
    ]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'No recipient',
        ],
    );

    Mail::assertNothingQueued();
    expect(LeaveRequest::query()->where('employee_id', $context['employee']->id)->exists())->toBeTrue();
});

test('disabling emails does not prevent submission update approval or rejection', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    ensureDefaultLeaveApprovalPolicy($context['company']);
    configureLeaveEmailNotificationSettings($context['company'], [
        'email_notifications_enabled' => false,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Workflow continues',
        ],
    );

    expect($leaveRequest->status)->toBe('pending');

    $updated = app(UpdateLeaveRequestWithApprovals::class)->handle(
        $leaveRequest,
        (int) $context['company']->id,
        [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-10',
            'end_date' => '2026-06-11',
            'reason' => 'Still editable',
        ],
    );

    expect($updated->reason)->toBe('Still editable');

    $approved = app(ApproveLeaveRequestStep::class)->handle(
        $updated,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    expect($approved->status)->toBe('approved');

    $rejectable = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-02',
            'reason' => 'Rejectable',
        ],
    );

    $rejected = app(RejectLeaveRequestStep::class)->handle(
        $rejectable,
        $context['managerUser'],
        (int) $context['company']->id,
        'Not needed',
    );

    expect($rejected->status)->toBe('rejected');
    Mail::assertNothingQueued();
});

test('intermediate approval still activates the next approval step when its email is disabled', function () {
    Mail::fake();
    seedAllLeaveEmailTemplates();

    $context = makeLeaveEmailNotificationControlFixtures();
    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Approver',
        'work_email' => 'hr-activate@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);
    configureLeaveEmailNotificationSettings($context['company'], [
        'notify_next_approver' => false,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-02',
            'reason' => 'Activate next',
        ],
        notify: false,
    );

    $afterFirst = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['managerUser'],
        (int) $context['company']->id,
    );

    $second = LeaveRequestApproval::query()
        ->where('leave_request_id', $afterFirst->id)
        ->where('sequence', 2)
        ->firstOrFail();

    expect($afterFirst->status)->toBe('pending')
        ->and($second->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and((int) $second->approver_user_id)->toBe((int) $hr['user']->id);

    Mail::assertNothingQueued();
});
