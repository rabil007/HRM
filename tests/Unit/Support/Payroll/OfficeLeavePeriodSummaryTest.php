<?php

use App\Models\Employee;
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

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $annualLeave->id,
        'start_date' => '2026-06-02',
        'end_date' => '2026-06-03',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $sickLeave->id,
        'start_date' => '2026-05-30',
        'end_date' => '2026-06-01',
        'total_days' => 3,
        'status' => 'approved',
    ]);

    createLeaveRequestRecord([
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

test('inactive leave type with approved historical leave still appears in office leave usage', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $emergency = LeaveType::factory()->for($company)->create([
        'name' => 'Emergency Leave',
        'code' => 'EL',
        'status' => 'inactive',
    ]);

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $emergency->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-07',
        'total_days' => 5,
        'status' => 'approved',
    ]);

    $summary = app(OfficeLeavePeriodSummary::class)->forEmployees(
        $company->id,
        '2026-08-01',
        '2026-08-31',
        [$employee->id],
    )->get($employee->id);

    expect($summary)->not->toBeNull()
        ->and($summary->totalLeaveDays)->toBe(5.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'EL')['days'])->toBe(5.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'EL')['payroll_treatment'])->toBe('paid');
});

test('soft-deleted legacy unpaid leave type is treated as unpaid after corrective backfill', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);
    $legacyUl = LeaveType::factory()->for($company)->create([
        'name' => 'Legacy Unpaid',
        'code' => 'UL',
        'payroll_treatment' => 'paid',
        'status' => 'inactive',
    ]);
    $legacyUl->delete();

    createLeaveRequestRecord([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $legacyUl->id,
        'start_date' => '2026-08-03',
        'end_date' => '2026-08-04',
        'total_days' => 2,
        'status' => 'approved',
    ]);

    $migration = require base_path('database/migrations/2026_09_21_171639_correct_soft_deleted_legacy_unpaid_leave_types_payroll_treatment.php');
    $migration->up();

    $summary = app(OfficeLeavePeriodSummary::class)->forEmployees(
        $company->id,
        '2026-08-01',
        '2026-08-31',
        [$employee->id],
    )->get($employee->id);

    expect($summary)->not->toBeNull()
        ->and($summary->totalLeaveDays)->toBe(2.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'UL')['days'])->toBe(2.0)
        ->and(collect($summary->leaveUsage)->firstWhere('code', 'UL')['payroll_treatment'])->toBe('unpaid');
});
