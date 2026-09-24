<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveApprovalMode;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestDecidedMail;
use App\Models\EmailTemplate;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SendLeaveRequestDecidedEmail;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Email\EmailTemplatePreview;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();
});

test('approved email placeholders separate department manager from deciding approver', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();

    $managed = makeManagedDepartment($context['company']);
    $managed['manager']->forceFill(['name' => 'Adam'])->save();
    $context['employee']->update(['department_id' => $managed['department']->id]);

    $rima = makeActionableApprover($context['company'], [
        'name' => 'Rima',
        'work_email' => 'rima-decided@example.com',
    ]);

    ensureDefaultLeaveApprovalPolicy($context['company'], [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $rima['employee']->id,
            'required' => true,
        ],
    ], LeaveApprovalMode::AllRequired);

    EmailTemplate::query()->where('slug', 'leave_request_approved')->update([
        'enabled' => true,
        'body_html' => "Manager: {{manager_name}}\nDecision by: {{approver_name}}\nApprovers: {{approver_names}}",
        'to_preset' => null,
        'cc_preset' => null,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-20',
            'end_date' => '2026-12-21',
            'reason' => 'Approved placeholder check',
        ],
        notify: false,
    );

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $rima['user'],
        (int) $context['company']->id,
    );

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail): bool {
        $html = $mail->render();
        $placeholders = app(SendLeaveRequestDecidedEmail::class)->placeholders(
            LeaveRequest::query()->latest('id')->firstOrFail()->load([
                'employee.department',
                'approvals.approverEmployee',
                'approver',
                'leaveType',
                'company',
            ]),
        );

        expect($placeholders['{{manager_name}}'])->toBe('Adam')
            ->and($placeholders['{{approver_name}}'])->toBe('Rima')
            ->and($placeholders['{{approver_names}}'])->toBe('Rima')
            ->and($mail->decidedByName)->toBe('Rima')
            ->and($mail->introMessage ?? '')->toContain('Manager: Adam')
            ->and($mail->introMessage ?? '')->toContain('Decision by: Rima')
            ->and($mail->introMessage ?? '')->toContain('Approvers: Rima')
            ->and($mail->introMessage ?? '')->not->toContain('{{manager_name}}')
            ->and($mail->introMessage ?? '')->not->toContain('{{approver_name}}')
            ->and($html)->toContain('Decided by')
            ->and($html)->toContain('Rima')
            ->and($html)->not->toContain('>Manager</');

        return true;
    });
});

test('rejected email placeholders use rejecting approver not department manager', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AllRequired);
    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();

    $managed = makeManagedDepartment($context['company']);
    $managed['manager']->forceFill(['name' => 'Adam'])->save();
    $context['employee']->update(['department_id' => $managed['department']->id]);

    ensureDefaultLeaveApprovalPolicy($context['company'], [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $context['maher']['employee']->id,
            'required' => true,
        ],
    ], LeaveApprovalMode::AllRequired);

    EmailTemplate::query()->where('slug', 'leave_request_rejected')->update([
        'enabled' => true,
        'body_html' => "Manager: {{manager_name}}\nDecision by: {{approver_name}}\nApprovers: {{approver_names}}",
        'to_preset' => null,
        'cc_preset' => null,
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-12-22',
            'end_date' => '2026-12-23',
            'reason' => 'Rejected placeholder check',
        ],
        notify: false,
    );

    app(RejectLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['maher']['user'],
        (int) $context['company']->id,
        'Not available',
    );

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail): bool {
        expect($mail->decidedByName)->toBe('Maher')
            ->and($mail->introMessage ?? '')->toContain('Manager: Adam')
            ->and($mail->introMessage ?? '')->toContain('Decision by: Maher')
            ->and($mail->introMessage ?? '')->toContain('Approvers: Maher');

        return true;
    });
});

test('any required decided email uses the acting approver not cancelled siblings', function () {
    $context = makeAnyRequiredModeContext(LeaveApprovalMode::AnyRequired);

    EmailTemplate::query()->where('slug', 'leave_request_approved')->update([
        'enabled' => true,
        'body_html' => 'Decision by: {{approver_name}}',
        'to_preset' => null,
        'cc_preset' => null,
    ]);

    $leaveRequest = submitAnyRequiredLeave($context, notify: false);

    app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest,
        $context['maher']['user'],
        (int) $context['company']->id,
    );

    $fresh = $leaveRequest->fresh(['approvals']);
    expect($fresh->approvals->firstWhere('approver_employee_id', $context['maher']['employee']->id)->status)
        ->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($fresh->approvals->firstWhere('approver_employee_id', $context['rima']['employee']->id)->status)
        ->toBe(LeaveRequestApprovalStatus::Cancelled);

    $placeholders = app(SendLeaveRequestDecidedEmail::class)->placeholders(
        $fresh->load(['employee.department', 'approvals.approverEmployee', 'approver', 'leaveType', 'company']),
    );

    expect($placeholders['{{approver_name}}'])->toBe('Maher')
        ->and($placeholders['{{approver_names}}'])->toBe('Maher')
        ->and($placeholders['{{approver_name}}'])->not->toBe('Rima');

    Mail::assertQueued(LeaveRequestDecidedMail::class, function (LeaveRequestDecidedMail $mail): bool {
        return $mail->decidedByName === 'Maher'
            && str_contains($mail->introMessage ?? '', 'Decision by: Maher');
    });
});

test('email template preview substitutes leave decided approver placeholders', function () {
    $approved = EmailTemplate::query()->where('slug', 'leave_request_approved')->firstOrFail();
    $approved->update([
        'body_html' => "Manager: {{manager_name}}\nDecision by: {{approver_name}}\nApprovers: {{approver_names}}",
    ]);

    $preview = app(EmailTemplatePreview::class)->render($approved);

    expect($preview['html'])->toContain('Manager: John Manager')
        ->and($preview['html'])->toContain('Decision by: Sara Approver')
        ->and($preview['html'])->toContain('Approvers: Sara Approver')
        ->and($preview['html'])->toContain('Decided by')
        ->and($preview['html'])->toContain('Sara Approver')
        ->and($preview['html'])->not->toContain('{{manager_name}}')
        ->and($preview['html'])->not->toContain('{{approver_name}}')
        ->and($preview['html'])->not->toContain('{{approver_names}}');
});
