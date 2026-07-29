<?php

use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Support\Attendance\Actions\SubmitLeaveRequestWithApprovals;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\ValidateLeaveRequestDateRange;
use Illuminate\Validation\ValidationException;

test('strict date range validator rejects impossible and malformed dates', function (mixed $start, mixed $end, string $field) {
    try {
        app(ValidateLeaveRequestDateRange::class)->handle($start, $end);
        expect(false)->toBeTrue('Expected ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }
})->with([
    'impossible february' => ['2026-02-30', '2026-03-01', 'start_date'],
    'impossible april' => ['2026-04-01', '2026-04-31', 'end_date'],
    'non-leap february 29' => ['2027-02-29', '2027-03-01', 'start_date'],
    'malformed start' => ['2026/03/01', '2026-03-02', 'start_date'],
    'missing start' => [null, '2026-03-02', 'start_date'],
    'missing end' => ['2026-03-01', '', 'end_date'],
    'reversed range' => ['2026-03-10', '2026-03-01', 'start_date'],
]);

test('strict date range validator accepts real calendar dates', function (string $start, string $end) {
    $dates = app(ValidateLeaveRequestDateRange::class)->handle($start, $end);

    expect($dates)->toBe([
        'start_date' => $start,
        'end_date' => $end,
    ]);
})->with([
    'leap day' => ['2028-02-29', '2028-03-01'],
    'normal range' => ['2026-03-01', '2026-03-05'],
]);

test('submit leave request rejects impossible dates with zero workflow side effects', function () {
    $context = makeFinalCorrectionContext();
    $beforeBalances = LeaveBalance::query()->where('company_id', $context['company']->id)->count();
    $beforeRequests = LeaveRequest::query()->where('company_id', $context['company']->id)->count();

    expect(fn () => app(SubmitLeaveRequestWithApprovals::class)->handle(
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-02-30',
            'end_date' => '2026-03-01',
            'reason' => 'Bad date',
        ],
        notify: false,
    ))->toThrow(ValidationException::class);

    expect(LeaveRequest::query()->where('company_id', $context['company']->id)->count())->toBe($beforeRequests)
        ->and(LeaveBalance::query()->where('company_id', $context['company']->id)->count())->toBe($beforeBalances)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe(0.0);
});

test('update leave request rejects impossible dates with zero workflow side effects', function () {
    $context = makeFinalCorrectionContext();
    $leaveRequest = submitPendingLeave($context, '2026-06-01', '2026-06-01');
    $pendingBefore = (float) LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');
    $approvalsBefore = $leaveRequest->approvals()->count();

    expect(fn () => app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-31',
            'reason' => 'Bad end',
        ],
    ))->toThrow(ValidationException::class);

    $fresh = $leaveRequest->fresh();

    expect($fresh->start_date?->toDateString())->toBe('2026-06-01')
        ->and($fresh->end_date?->toDateString())->toBe('2026-06-01')
        ->and($fresh->approvals()->count())->toBe($approvalsBefore)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe($pendingBefore);
});
