<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Attendance\CalculateLeaveRequestDays;
use App\Support\Payroll\CountLeaveDaysInRange;
use App\Support\Payroll\OfficeLeavePeriodSummary;

test('count leave days in range clips request dates to payroll period', function () {
    $counter = new CountLeaveDaysInRange(new CalculateLeaveRequestDays);

    expect($counter->count('2026-06-01', '2026-06-10', '2026-06-01', '2026-06-05'))->toBe(5.0)
        ->and($counter->count('2026-06-01', '2026-06-10', '2026-06-06', '2026-06-30'))->toBe(5.0)
        ->and($counter->count('2026-06-01', '2026-06-10', '2026-06-20', '2026-06-30'))->toBe(0.0);
});

test('office leave period summary aggregates approved leave by employee and type', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $annualLeave = LeaveType::factory()->for($company)->create([
        'name' => 'Annual Leave',
        'code' => 'AL',
        'status' => 'active',
    ]);
    $sickLeave = LeaveType::factory()->for($company)->create([
        'name' => 'Sick Leave',
        'code' => 'SL',
        'status' => 'active',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $annualLeave->id,
        'start_date' => '2026-06-02',
        'end_date' => '2026-06-03',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $sickLeave->id,
        'start_date' => '2026-05-30',
        'end_date' => '2026-06-01',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    LeaveRequest::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $annualLeave->id,
        'start_date' => '2026-06-10',
        'end_date' => '2026-06-12',
        'total_days' => 3,
        'status' => 'pending',
    ]);

    $summary = app(OfficeLeavePeriodSummary::class)->forEmployees(
        $company->id,
        '2026-06-01',
        '2026-06-05',
        [$employee->id],
    )->get($employee->id);

    expect($summary)->not->toBeNull()
        ->and($summary->totalLeaveDays)->toBe(3.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'AL')['days'])->toBe(2.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'SL')['days'])->toBe(1.0);
});
