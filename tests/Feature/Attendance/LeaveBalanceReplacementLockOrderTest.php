<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\Actions\UpdateLeaveRequestWithApprovals;
use App\Support\Attendance\LeaveBalanceManager;

test('replace pending reservation locks balance keys in deterministic order across employee type and year changes', function () {
    $context = makeFinalCorrectionContext(daysPerYear: 40);
    $otherEmployee = Employee::factory()->forCompany($context['company'])->create([
        'status' => 'active',
        'department_id' => $context['employee']->department_id,
    ]);
    $otherType = LeaveType::factory()->for($context['company'])->create([
        'status' => 'active',
        'days_per_year' => 40,
        'name' => 'Other Leave',
    ]);

    $balances = app(LeaveBalanceManager::class);
    $balances->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2026);
    $balances->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2027);
    $balances->ensureEmployeeYear((int) $context['company']->id, (int) $otherEmployee->id, 2026);
    $balances->ensureEmployeeYear((int) $context['company']->id, (int) $otherEmployee->id, 2027);
    $balances->ensureEmployeeYear((int) $context['company']->id, (int) $context['employee']->id, 2026);
    foreach ([$context['leaveType']->id, $otherType->id] as $typeId) {
        foreach ([$context['employee']->id, $otherEmployee->id] as $employeeId) {
            foreach ([2026, 2027] as $year) {
                $balances->ensureEmployeeYear((int) $context['company']->id, (int) $employeeId, $year);
                LeaveBalance::query()
                    ->where('company_id', $context['company']->id)
                    ->where('employee_id', $employeeId)
                    ->where('leave_type_id', $typeId)
                    ->where('year', $year)
                    ->update([
                        'entitled_days' => 40,
                        'pending_days' => 0,
                        'used_days' => 0,
                        'carried_days' => 0,
                    ]);
            }
        }
    }

    $leaveRequest = submitPendingLeave($context, '2026-12-30', '2027-01-02');

    $pendingOld2026 = (float) LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2026)
        ->value('pending_days');
    $pendingOld2027 = (float) LeaveBalance::query()
        ->where('employee_id', $context['employee']->id)
        ->where('leave_type_id', $context['leaveType']->id)
        ->where('year', 2027)
        ->value('pending_days');

    expect($pendingOld2026)->toBeGreaterThan(0.0)
        ->and($pendingOld2027)->toBeGreaterThan(0.0);

    // Opposite-direction key change: higher employee/type/year first in natural id order,
    // replacement targets lower keys so lock sorting must not depend on mutation direction.
    $updated = app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $leaveRequest,
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $otherEmployee->id,
            'leave_type_id' => $otherType->id,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-03',
            'reason' => 'Replacement lock order',
        ],
    );

    expect($updated->employee_id)->toBe($otherEmployee->id)
        ->and($updated->leave_type_id)->toBe($otherType->id)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe(0.0)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2027)
            ->value('pending_days'))->toBe(0.0)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $otherEmployee->id)
            ->where('leave_type_id', $otherType->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe(3.0);

    // Reverse direction: back onto original employee/type with a different year span.
    $restored = app(UpdateLeaveRequestWithApprovals::class)->handle(
        leaveRequest: $updated,
        companyId: (int) $context['company']->id,
        attributes: [
            'employee_id' => $context['employee']->id,
            'leave_type_id' => $context['leaveType']->id,
            'start_date' => '2027-02-01',
            'end_date' => '2027-02-02',
            'reason' => 'Opposite replacement',
        ],
    );

    expect($restored->employee_id)->toBe($context['employee']->id)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $otherEmployee->id)
            ->where('leave_type_id', $otherType->id)
            ->where('year', 2026)
            ->value('pending_days'))->toBe(0.0)
        ->and((float) LeaveBalance::query()
            ->where('employee_id', $context['employee']->id)
            ->where('leave_type_id', $context['leaveType']->id)
            ->where('year', 2027)
            ->value('pending_days'))->toBe(2.0);
});
