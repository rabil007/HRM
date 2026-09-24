<?php

use App\Models\LeaveBalance;
use App\Support\Attendance\LeaveBalanceManager;
use App\Support\Settings\CompanyTimezone;

test('synchronize balance key preserves opening used days', function () {
    ['company' => $company, 'employee' => $employee, 'leaveType' => $leaveType] = authorizeLeaveReport();
    $businessYear = (int) now(CompanyTimezone::forCompanyId($company->id))->year;

    $balance = LeaveBalance::factory()->forEmployee($employee)->forLeaveType($leaveType)->create([
        'year' => $businessYear,
        'entitled_days' => 30,
        'carried_days' => 0,
        'opening_used_days' => 8,
        'opening_balance_as_of' => "{$businessYear}-01-05",
        'opening_balance_note' => 'Prior system',
        'used_days' => 0,
        'pending_days' => 0,
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => "{$businessYear}-09-01",
        'end_date' => "{$businessYear}-09-03",
        'status' => 'approved',
        'total_days' => 3.0,
        'decided_at' => now(),
    ]);

    app(LeaveBalanceManager::class)->synchronizeBalanceKey(
        (int) $company->id,
        (int) $employee->id,
        (int) $leaveType->id,
        $businessYear,
        createIfMissing: false,
    );

    $balance->refresh();

    expect((float) $balance->opening_used_days)->toBe(8.0)
        ->and($balance->opening_balance_as_of?->toDateString())->toBe("{$businessYear}-01-05")
        ->and($balance->opening_balance_note)->toBe('Prior system')
        ->and((float) $balance->used_days)->toBe(3.0)
        ->and((float) $balance->remaining_days)->toBe(19.0);
});
