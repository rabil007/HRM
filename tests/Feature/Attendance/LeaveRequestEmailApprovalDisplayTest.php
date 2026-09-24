<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\LeaveApprovalPolicy;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\ComposeLeaveRequestSubmittedMail;
use App\Support\Attendance\PresentLeaveRequestEmailApprovalContext;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();
});

test('hr approver is shown as current approver not manager in submission email', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();

    $hr = makeActionableApprover($context['company'], [
        'name' => 'Rima HR Approver',
        'work_email' => 'rima-hr-label@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ], LeaveApprovalMode::AllRequired);

    $managed = makeManagedDepartment($context['company']);
    $context['employee']->update(['department_id' => $managed['department']->id]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-02',
            'reason' => 'HR label check',
        ],
        notify: true,
    );

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        if (! $mail->hasTo('rima-hr-label@example.com')) {
            return false;
        }

        $html = $mail->render();

        expect($mail->approvalLabel)->toBe(PresentLeaveRequestEmailApprovalContext::LABEL_CURRENT)
            ->and($mail->approvalNames)->toBe(['Rima HR Approver'])
            ->and($html)->toContain('Current approver')
            ->and($html)->toContain('Rima HR Approver')
            ->and($html)->not->toContain('>Manager</');

        return true;
    });
});

test('specific employee approver uses current approver label', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $context['maher']['employee']->id,
            'required' => true,
        ],
    ], LeaveApprovalMode::AllRequired);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-05',
            'end_date' => '2026-12-06',
            'reason' => 'Specific employee label',
        ],
        notify: true,
    );

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['maher']['employee']->work_email)
            && $mail->approvalLabel === PresentLeaveRequestEmailApprovalContext::LABEL_CURRENT
            && $mail->approvalNames === ['Maher']
            && ! str_contains($mail->render(), '>Manager</');
    });
});

test('any required email lists all pending required approvers and omits notify only', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AnyRequired);
    submitAnyRequiredLeave($context, notify: true);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        if (! $mail->hasTo($context['rima']['employee']->work_email)) {
            return false;
        }

        $html = $mail->render();

        expect($mail->approvalLabel)->toBe(PresentLeaveRequestEmailApprovalContext::LABEL_APPROVERS)
            ->and($mail->approvalNames)->toBe(['Rima', 'Maher'])
            ->and($mail->approvalHelpText)->toBe(PresentLeaveRequestEmailApprovalContext::HELP_ANY_REQUIRED)
            ->and($html)->toContain('Approvers')
            ->and($html)->toContain('Rima')
            ->and($html)->toContain('Maher')
            ->and($html)->toContain('Any one of these approvers can act on this request.')
            ->and($html)->not->toContain('Adam')
            ->and($html)->not->toContain('>Manager</');

        return true;
    });
});

test('fyi email keeps no-action wording and does not list notify-only recipient as approver', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AnyRequired);
    submitAnyRequiredLeave($context, notify: true);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        if (! $mail->hasTo($context['adam']['employee']->work_email)) {
            return false;
        }

        expect($mail->introMessage ?? '')->toContain('for your information')
            ->and($mail->introMessage ?? '')->toContain('No approval or action is required from you')
            ->and($mail->approvalLabel)->toBe(PresentLeaveRequestEmailApprovalContext::LABEL_APPROVERS)
            ->and($mail->approvalNames)->toBe(['Rima', 'Maher'])
            ->and($mail->approvalNames)->not->toContain('Adam');

        return true;
    });
});

test('all required waiting future approvers are not listed as current approvers', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    $leaveRequest = submitAnyRequiredLeave($context, notify: true);

    $approvals = $leaveRequest->approvals()->orderBy('sequence')->get();
    expect($approvals[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($approvals[1]->status)->toBe(LeaveRequestApprovalStatus::Waiting);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use ($context): bool {
        return $mail->hasTo($context['rima']['employee']->work_email)
            && $mail->approvalLabel === PresentLeaveRequestEmailApprovalContext::LABEL_CURRENT
            && $mail->approvalNames === ['Rima']
            && ! in_array('Maher', $mail->approvalNames, true)
            && ! in_array('Adam', $mail->approvalNames, true);
    });
});

test('manager_name placeholder resolves department manager not hr approver', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();

    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Placeholder Person',
        'work_email' => 'hr-placeholder@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);
    $managed = makeManagedDepartment($context['company']);
    $context['employee']->update(['department_id' => $managed['department']->id]);

    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ], LeaveApprovalMode::AllRequired);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-10',
            'end_date' => '2026-12-11',
            'reason' => 'Placeholder check',
        ],
        notify: false,
    )->load(['employee.department', 'approvals.approverEmployee', 'leaveType', 'company']);

    $placeholders = app(ComposeLeaveRequestSubmittedMail::class)->placeholders($leaveRequest);

    expect($placeholders['{{manager_name}}'])->toBe((string) $managed['manager']->name)
        ->and($placeholders['{{manager_name}}'])->not->toBe('HR Placeholder Person')
        ->and($placeholders['{{approver_name}}'])->toBe('HR Placeholder Person')
        ->and($placeholders['{{approver_names}}'])->toBe('HR Placeholder Person');
});
