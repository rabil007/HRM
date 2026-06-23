<?php

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\AttendanceRecord;
use App\Models\ContractSalaryComponent;
use App\Support\Payroll\OfficeAttendanceSummary;
use App\Support\Payroll\OfficePayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('office payroll calculator prorates monthly components by attendance ratio', function () {
    $summary = new OfficeAttendanceSummary(
        presentDays: 20.0,
        absentDays: 2.0,
        overtimeHours: 0.0,
        lateMinutes: 0,
        recordCount: 20,
        workingDays: 22,
    );

    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 11000),
        makeOfficeCalculatorComponent(SalaryComponentCode::Housing, 2200),
        makeOfficeCalculatorComponent(SalaryComponentCode::Transport, 1100),
        makeOfficeCalculatorComponent(SalaryComponentCode::Other, 550),
    ]);

    $result = (new OfficePayrollCalculator)->calculate($summary, $components, 22);

    $ratio = 20 / 22;

    expect($result['calculation_breakdown']['proration_ratio'])->toBe(round($ratio, 4))
        ->and($result['basic_salary'])->toBe(number_format(11000 * $ratio, 2, '.', ''))
        ->and($result['housing_allowance'])->toBe(number_format(2200 * $ratio, 2, '.', ''))
        ->and($result['transport_allowance'])->toBe(number_format(1100 * $ratio, 2, '.', ''))
        ->and($result['other_allowances'])->toBe(number_format(550 * $ratio, 2, '.', ''))
        ->and($result['gross_salary'])->toBe(number_format(14850 * $ratio, 2, '.', ''))
        ->and($result['net_salary'])->toBe($result['gross_salary'])
        ->and($result['working_days'])->toBe(22)
        ->and($result['present_days'])->toBe(20.0);
});

test('office payroll calculator uses OT_RATE when present on contract', function () {
    $summary = new OfficeAttendanceSummary(
        presentDays: 22.0,
        absentDays: 0.0,
        overtimeHours: 10.0,
        lateMinutes: 0,
        recordCount: 22,
        workingDays: 22,
    );

    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 10000),
        makeOfficeCalculatorComponent(SalaryComponentCode::OtRate, 75),
    ]);

    $result = (new OfficePayrollCalculator)->calculate($summary, $components, 22);

    expect($result['overtime_pay'])->toBe('750.00')
        ->and($result['gross_salary'])->toBe('10750.00');
});

test('office payroll calculator derives overtime hourly rate from basic when OT_RATE missing', function () {
    $summary = new OfficeAttendanceSummary(
        presentDays: 22.0,
        absentDays: 0.0,
        overtimeHours: 8.0,
        lateMinutes: 0,
        recordCount: 22,
        workingDays: 22,
    );

    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 8800),
    ]);

    $result = (new OfficePayrollCalculator)->calculate($summary, $components, 22);

    // 8800 / (22 * 8) = 50 per hour * 8 hours = 400
    expect($result['overtime_pay'])->toBe('400.00')
        ->and($result['gross_salary'])->toBe('9200.00');
});

test('office payroll calculator requires an active basic monthly salary', function () {
    $summary = OfficeAttendanceSummary::empty(22);

    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Housing, 2000),
    ]);

    (new OfficePayrollCalculator)->calculate($summary, $components, 22);
})->throws(ValidationException::class);

test('office attendance summary counts present late half day and holiday on working days', function () {
    $records = Collection::make([
        new AttendanceRecord(['date' => '2026-06-02', 'status' => AttendanceRecord::STATUS_PRESENT, 'overtime_hours' => 1]),
        new AttendanceRecord(['date' => '2026-06-03', 'status' => AttendanceRecord::STATUS_LATE, 'late_minutes' => 15, 'overtime_hours' => 0.5]),
        new AttendanceRecord(['date' => '2026-06-04', 'status' => AttendanceRecord::STATUS_HALF_DAY]),
        new AttendanceRecord(['date' => '2026-06-05', 'status' => AttendanceRecord::STATUS_HOLIDAY]),
        new AttendanceRecord(['date' => '2026-06-06', 'status' => AttendanceRecord::STATUS_WEEKEND]),
    ]);

    $summary = OfficeAttendanceSummary::fromRecords($records, 5, [1, 2, 3, 4, 5]);

    expect($summary->presentDays)->toBe(3.5)
        ->and($summary->absentDays)->toBe(1.5)
        ->and($summary->overtimeHours)->toBe(1.5)
        ->and($summary->lateMinutes)->toBe(15)
        ->and($summary->recordCount)->toBe(5);
});

function makeOfficeCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
