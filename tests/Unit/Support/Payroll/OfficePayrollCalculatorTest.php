<?php

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Support\Payroll\OfficePayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('office payroll calculator pays full monthly salary with zero deductions', function () {
    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 11000),
        makeOfficeCalculatorComponent(SalaryComponentCode::Housing, 2200),
        makeOfficeCalculatorComponent(SalaryComponentCode::Transport, 1100),
        makeOfficeCalculatorComponent(SalaryComponentCode::Other, 550),
    ]);

    $leaveUsage = [
        [
            'leave_type_id' => 1,
            'code' => 'AL',
            'name' => 'Annual Leave',
            'color' => '#3b82f6',
            'days' => 2.0,
        ],
    ];

    $result = (new OfficePayrollCalculator)->calculate($components, 22, 2.0, $leaveUsage);

    expect($result['basic_salary'])->toBe('11000.00')
        ->and($result['housing_allowance'])->toBe('2200.00')
        ->and($result['transport_allowance'])->toBe('1100.00')
        ->and($result['other_allowances'])->toBe('550.00')
        ->and($result['overtime_pay'])->toBe('0.00')
        ->and($result['gross_salary'])->toBe('14850.00')
        ->and($result['net_salary'])->toBe('14850.00')
        ->and($result['total_deductions'])->toBe('0.00')
        ->and($result['working_days'])->toBe(22)
        ->and($result['present_days'])->toBe(20.0)
        ->and($result['leave_days'])->toBe(2.0)
        ->and($result['calculation_breakdown']['leave_usage'])->toBe($leaveUsage);
});

test('office payroll calculator requires an active basic monthly salary', function () {
    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Housing, 2000),
    ]);

    (new OfficePayrollCalculator)->calculate($components, 22);
})->throws(ValidationException::class);

function makeOfficeCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}

test('office payroll deducts unpaid leave via payroll_treatment not mutable code', function () {
    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 11000),
        makeOfficeCalculatorComponent(SalaryComponentCode::Housing, 2200),
        makeOfficeCalculatorComponent(SalaryComponentCode::Transport, 1100),
        makeOfficeCalculatorComponent(SalaryComponentCode::Other, 550),
    ]);

    $leaveUsage = [
        [
            'leave_type_id' => 10,
            'code' => 'UNPL',
            'name' => 'Personal Unpaid Leave',
            'color' => '#ef4444',
            'days' => 2.0,
            'payroll_treatment' => 'unpaid',
        ],
        [
            'leave_type_id' => 11,
            'code' => 'UL',
            'name' => 'Looks Unpaid By Code',
            'color' => '#f59e0b',
            'days' => 3.0,
            'payroll_treatment' => 'paid',
        ],
    ];

    $result = (new OfficePayrollCalculator)->calculate($components, 22, 5.0, $leaveUsage);

    $dailyRate = 14850 / 22;
    $expectedDeduction = round($dailyRate * 2.0, 2);

    expect($result['unpaid_leave_deduction'])->toBe(number_format($expectedDeduction, 2, '.', ''))
        ->and($result['leave_days'])->toBe(5.0)
        ->and($result['present_days'])->toBe(17.0);
});

test('legacy UL UNPAID and LOP codes remain unpaid after treatment backfill semantics', function (string $code) {
    $components = Collection::make([
        makeOfficeCalculatorComponent(SalaryComponentCode::Basic, 10000),
    ]);

    $leaveUsage = [[
        'leave_type_id' => 1,
        'code' => $code,
        'name' => 'Legacy unpaid',
        'color' => null,
        'days' => 1.0,
        'payroll_treatment' => 'unpaid',
    ]];

    $result = (new OfficePayrollCalculator)->calculate($components, 20, 1.0, $leaveUsage);

    expect($result['unpaid_leave_deduction'])->toBe('500.00');
})->with(['UL', 'UNPAID', 'LOP']);
