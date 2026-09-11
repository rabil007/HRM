<?php

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\LeaveApprovalPolicy;
use App\Models\LeaveRequestApproval;
use App\Support\Attendance\Actions\ApproveLeaveRequestStep;
use App\Support\Attendance\Actions\RejectLeaveRequestStep;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\AssertLeaveApprovalWorkflowInvariant;

test('notify-only leading steps are skipped at submission and workflow progresses through required steps', function () {
    $context = makeFinalCorrectionContext();
    $hr = makeActionableApprover($context['company']);
    $notifyOnly = makeActionableApprover($context['company'], [
        'name' => 'Notify Only Listener',
        'work_email' => 'notify-only-listener@example.com',
    ]);
    configureCompanyLeaveApprovalSettings($context['company'], $hr['employee']);

    LeaveApprovalPolicy::query()->where('company_id', $context['company']->id)->delete();
    ensureDefaultLeaveApprovalPolicy($context['company'], [
        [
            'type' => LeaveApprovalApproverType::SpecificEmployee,
            'employee_id' => $notifyOnly['employee']->id,
            'required' => false,
        ],
        ['type' => LeaveApprovalApproverType::DepartmentManager, 'required' => true],
        ['type' => LeaveApprovalApproverType::HrApprover, 'required' => true],
    ]);

    $leaveRequest = app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-08-03',
            'end_date' => '2026-08-03',
            'reason' => 'Notify-only leading',
        ],
        notify: false,
    );

    $steps = LeaveRequestApproval::query()
        ->where('leave_request_id', $leaveRequest->id)
        ->orderBy('sequence')
        ->get();

    expect($steps)->toHaveCount(3)
        ->and($steps[0]->is_required)->toBeFalse()
        ->and($steps[0]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and($steps[0]->acted_at)->not->toBeNull()
        ->and((int) $steps[0]->approver_employee_id)->toBe((int) $notifyOnly['employee']->id)
        ->and($steps[1]->is_required)->toBeTrue()
        ->and($steps[1]->status)->toBe(LeaveRequestApprovalStatus::Pending)
        ->and((int) $steps[1]->approver_employee_id)->toBe((int) $context['managerEmployee']->id)
        ->and($steps[2]->is_required)->toBeTrue()
        ->and($steps[2]->status)->toBe(LeaveRequestApprovalStatus::Waiting)
        ->and((int) $steps[2]->approver_employee_id)->toBe((int) $hr['employee']->id);

    $approverEmployeeIds = $steps->pluck('approver_employee_id')->filter()->map(fn ($id) => (int) $id)->all();
    expect($approverEmployeeIds)->not->toContain((int) $context['employee']->id)
        ->and(count($approverEmployeeIds))->toBe(count(array_unique($approverEmployeeIds)));

    app(AssertLeaveApprovalWorkflowInvariant::class)->forPendingRequest($leaveRequest->fresh(), $steps);

    try {
        app(ApproveLeaveRequestStep::class)->handle(
            $leaveRequest->fresh(),
            $notifyOnly['user'],
            (int) $context['company']->id,
        );
        expect(false)->toBeTrue('Notify-only listener must not approve a skipped step');
    } catch (Throwable) {
        // Authorization or invariant rejection — notify-only steps are not actionable.
    }

    try {
        app(RejectLeaveRequestStep::class)->handle(
            $leaveRequest->fresh(),
            $notifyOnly['user'],
            (int) $context['company']->id,
            'Cannot act on notify-only',
        );
        expect(false)->toBeTrue('Notify-only listener must not reject a skipped step');
    } catch (Throwable) {
        // Authorization or invariant rejection — notify-only steps are not actionable.
    }

    expect($leaveRequest->fresh()->status)->toBe('pending')
        ->and($steps[0]->fresh()->status)->toBe(LeaveRequestApprovalStatus::Skipped);

    $afterManager = app(ApproveLeaveRequestStep::class)->handle(
        $leaveRequest->fresh(),
        $context['managerUser'],
        (int) $context['company']->id,
    );

    $afterSteps = LeaveRequestApproval::query()
        ->where('leave_request_id', $afterManager->id)
        ->orderBy('sequence')
        ->get();

    expect($afterSteps[0]->status)->toBe(LeaveRequestApprovalStatus::Skipped)
        ->and($afterSteps[1]->status)->toBe(LeaveRequestApprovalStatus::Approved)
        ->and($afterSteps[2]->status)->toBe(LeaveRequestApprovalStatus::Pending);
});
