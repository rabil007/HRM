<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Mail\LeaveRequestSubmittedMail;
use App\Models\Company;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveApprovalNeedsActionCounter;
use App\Support\Attendance\LeaveRequestVisibility;
use Database\Seeders\EmailTemplatesSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    (new EmailTemplatesSeeder)->run();
});

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     leaveType: LeaveType,
 *     managerUser: User,
 *     managerEmployee: Employee,
 *     hr: array{employee: Employee, user: User},
 *     listenerA: array{employee: Employee, user: User},
 *     listenerB: array{employee: Employee, user: User}
 * }
 */
function makeNotifyOnlyFixtures(): array
{
    $context = makeFinalCorrectionContext();
    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Approver',
        'work_email' => 'hr-notify-only@example.com',
    ]);
    $listenerA = makeActionableApprover($context['company'], [
        'name' => 'Listener A',
        'work_email' => 'listener-a@example.com',
    ]);
    $listenerB = makeActionableApprover($context['company'], [
        'name' => 'Listener B',
        'work_email' => 'listener-b@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $listenerA['employee']->id,
            'required' => false,
        ],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $listenerB['employee']->id,
            'required' => false,
        ],
    ]);

    return [
        'company' => $context['company'],
        'employee' => $context['employee'],
        'leaveType' => $context['leaveType'],
        'managerUser' => $context['managerUser'],
        'managerEmployee' => $context['managerEmployee'],
        'hr' => $hr,
        'listenerA' => $listenerA,
        'listenerB' => $listenerB,
    ];
}

function submitNotifyOnlyRequest(array $fixtures, string $reason = 'Notify only flow'): LeaveRequest
{
    return app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $fixtures['company']->id,
        attributes: [
            'employee_id' => $fixtures['employee']->id,
            'leave_type_id' => $fixtures['leaveType']->id,
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-02',
            'reason' => $reason,
        ],
        notify: true,
    );
}

test('required first step is pending while notify-only steps stay skipped and later required waits', function () {
    $fixtures = makeNotifyOnlyFixtures();
    $leaveRequest = submitNotifyOnlyRequest($fixtures);

    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect($steps)->toHaveCount(4)
        ->and($steps[0]->is_required)->toBeTrue()
        ->and($steps[0]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and((int) $steps[0]->approver_user_id)->toBe((int) $fixtures['managerUser']->id)
        ->and($steps[1]->is_required)->toBeFalse()
        ->and($steps[1]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and($steps[1]->acted_at)->not->toBeNull()
        ->and((int) $steps[1]->approver_employee_id)->toBe((int) $fixtures['listenerA']['employee']->id)
        ->and($steps[2]->is_required)->toBeTrue()
        ->and($steps[2]->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and((int) $steps[2]->approver_employee_id)->toBe((int) $fixtures['hr']['employee']->id)
        ->and($steps[3]->is_required)->toBeFalse()
        ->and($steps[3]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and((int) $steps[3]->approver_employee_id)->toBe((int) $fixtures['listenerB']['employee']->id);
});

test('notify-only users cannot approve or reject and do not appear in needs action or sidebar count', function () {
    $fixtures = makeNotifyOnlyFixtures();
    $leaveRequest = submitNotifyOnlyRequest($fixtures);
    $visibility = app(LeaveRequestVisibility::class);
    $counter = app(LeaveApprovalNeedsActionCounter::class);
    $companyId = (int) $fixtures['company']->id;

    expect($visibility->canApproveCurrentStep($leaveRequest, $fixtures['listenerA']['user'], $companyId))->toBeFalse()
        ->and($visibility->canApproveCurrentStep($leaveRequest, $fixtures['hr']['user'], $companyId))->toBeFalse()
        ->and($visibility->canApproveCurrentStep($leaveRequest, $fixtures['managerUser'], $companyId))->toBeTrue()
        ->and($counter->count($fixtures['listenerA']['user'], $companyId))->toBe(0)
        ->and($counter->count($fixtures['listenerB']['user'], $companyId))->toBe(0)
        ->and($counter->count($fixtures['managerUser'], $companyId))->toBe(1)
        ->and($counter->count($fixtures['hr']['user'], $companyId))->toBe(0);

    $awaiting = LeaveRequest::query();
    $visibility->applyAwaitingMyApprovalScope($awaiting, $fixtures['listenerA']['user'], $companyId);
    expect($awaiting->count())->toBe(0);

    try {
        app(ApproveLeaveRequestStep::class)->handle(
            $leaveRequest->fresh(),
            $fixtures['listenerA']['user'],
            $companyId,
        );
        expect(false)->toBeTrue('Notify-only listener must not approve');
    } catch (Throwable) {
        // Authorization or invariant rejection.
    }

    try {
        app(RejectLeaveRequestStep::class)->handle(
            $leaveRequest->fresh(),
            $fixtures['listenerB']['user'],
            $companyId,
            'Should not reject',
        );
        expect(false)->toBeTrue('Notify-only listener must not reject');
    } catch (Throwable) {
        // Authorization or invariant rejection.
    }

    expect($leaveRequest->fresh()->status)->toBe('pending');
});

test('notify-only users receive FYI mail while future required approver does not on submission', function () {
    $fixtures = makeNotifyOnlyFixtures();
    submitNotifyOnlyRequest($fixtures);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('dept-manager@example.com')
            && str_contains($mail->introMessage ?? '', 'pending your review');
    });

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('listener-a@example.com')
            && str_contains($mail->introMessage ?? '', 'for your information')
            && ! str_contains(strtolower($mail->introMessage ?? ''), 'requires your approval')
            && ! str_contains(strtolower($mail->introMessage ?? ''), 'pending your review');
    });

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('listener-b@example.com')
            && str_contains($mail->introMessage ?? '', 'for your information');
    });

    Mail::assertNotQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('hr-notify-only@example.com');
    });
});

test('notify-only does not block progression and future required approver is notified after prior approval', function () {
    $fixtures = makeNotifyOnlyFixtures();
    $leaveRequest = submitNotifyOnlyRequest($fixtures);

    Mail::fake();

    $afterManager = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
    );

    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $afterManager->id)
        ->orderBy('sequence')
        ->get();

    expect($afterManager->status)->toBe('pending')
        ->and($steps[0]->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($steps[1]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and($steps[2]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and($steps[3]->status)->toBe(LeaveRequestApprovalStatus::Skipped);

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('hr-notify-only@example.com')
            && str_contains($mail->introMessage ?? '', 'requires your approval');
    });

    Mail::assertNotQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('listener-a@example.com') || $mail->hasTo('listener-b@example.com');
    });
});

test('rejecting current required step stops progression while notify-only remains skipped', function () {
    $fixtures = makeNotifyOnlyFixtures();
    $leaveRequest = submitNotifyOnlyRequest($fixtures, 'Reject flow');

    $rejected = app(RejectLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $fixtures['managerUser'],
        (int) $fixtures['company']->id,
        'Coverage conflict',
    );

    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $rejected->id)
        ->orderBy('sequence')
        ->get();

    expect($rejected->status)->toBe('rejected')
        ->and($steps[0]->status)->toBe(LeaveRequestApprovalStatus::Rejected)
        ->and($steps[1]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and($steps[2]->status)->toBe(LeaveRequestApprovalStatus::Cancelled)
        ->and($steps[3]->status)->toBe(LeaveRequestApprovalStatus::Skipped);
});

test('duplicate required and notify-only person is treated as approver without duplicate FYI mail', function () {
    $context = makeFinalCorrectionContext();
    $hr = makeActionableApprover($context['company'], [
        'name' => 'HR Dup',
        'work_email' => 'hr-dup@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $context['managerEmployee']->id,
            'required' => false,
        ],
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
    ]);

    app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-10',
            'reason' => 'Duplicate person',
        ],
        notify: true,
    );

    $managerMails = 0;

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail) use (&$managerMails): bool {
        if ($mail->hasTo('dept-manager@example.com')) {
            $managerMails++;

            return str_contains($mail->introMessage ?? '', 'pending your review');
        }

        return false;
    });

    expect($managerMails)->toBe(1);
});

test('disabled submission notification setting suppresses notify-only FYI email', function () {
    $fixtures = makeNotifyOnlyFixtures();

    CompanyLeaveApprovalSetting::forCompany($fixtures['company']->id)->update([
        'email_notifications_enabled' => true,
        'notify_on_submission' => false,
    ]);

    submitNotifyOnlyRequest($fixtures, 'Suppressed FYI');

    Mail::assertNotQueued(LeaveRequestSubmittedMail::class);
});

test('disabled notify-only template suppresses FYI while actionable submission mail still sends', function () {
    $fixtures = makeNotifyOnlyFixtures();

    EmailTemplate::query()->where('slug', 'leave_request_notification_only')->update([
        'enabled' => false,
    ]);

    submitNotifyOnlyRequest($fixtures, 'Template off');

    Mail::assertQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('dept-manager@example.com');
    });

    Mail::assertNotQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('listener-a@example.com') || $mail->hasTo('listener-b@example.com');
    });
});

test('cross-company notify-only employee is not resolved into the snapshot', function () {
    $fixtures = makeNotifyOnlyFixtures();
    $foreign = makeFinalCorrectionContext();
    $foreignListener = makeActionableApprover($foreign['company'], [
        'name' => 'Foreign Listener',
        'work_email' => 'foreign-listener@example.com',
    ]);

    LeaveApprovalPolicy::query()->where('company_id', $fixtures['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($fixtures['company'], [
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $foreignListener['employee']->id,
            'required' => false,
        ],
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $fixtures['company']->id,
        attributes: [
            'employee_id' => $fixtures['employee']->id,
            'leave_type_id' => $fixtures['leaveType']->id,
            'start_date' => '2026-09-15',
            'end_date' => '2026-09-15',
            'reason' => 'Cross company listener',
        ],
        notify: true,
    );

    $approverIds = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->pluck('approver_employee_id')
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($approverIds)->not->toContain((int) $foreignListener['employee']->id);

    Mail::assertNotQueued(LeaveRequestSubmittedMail::class, function (LeaveRequestSubmittedMail $mail): bool {
        return $mail->hasTo('foreign-listener@example.com');
    });
});
