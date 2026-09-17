<?php

use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewMonthlyPayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('crew monthly payroll calculator prorates monthly components from unpaid leave days', function () {
    $timesheet = new CrewTimesheet([
        'unpaid_leave_days' => 5,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('BASIC', 5000),
        makeMonthlyCalculatorComponent('HOUSING', 2000),
        makeMonthlyCalculatorComponent('TRANSPORT', 1000),
        makeMonthlyCalculatorComponent('OTHER', 500),
    ]);

    $result = (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);

    expect($result['calculation_breakdown']['salary_structure'])->toBe('monthly')
        ->and($result['calculation_breakdown']['leave_days'])->toBe(5.0)
        ->and($result['calculation_breakdown']['lines'])->toMatchArray([
            'basic' => 4166.67,
            'housing' => 1666.67,
            'transport' => 833.33,
            'other' => 416.67,
            'unpaid_leave_deduction' => 1416.67,
            'other_deduction' => 50.0,
        ])
        ->and($result['gross_salary'])->toBe('7183.34')
        ->and($result['total_deductions'])->toBe('50.00')
        ->and($result['net_salary'])->toBe('7133.34');
});

test('crew monthly payroll calculator requires basic monthly salary', function () {
    $timesheet = new CrewTimesheet([
        'unpaid_leave_days' => 2,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('HOUSING', 2000),
    ]);

    (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);
})->throws(ValidationException::class);

test('crew monthly payroll calculator pays full salary when there is no unpaid leave', function () {
    $timesheet = new CrewTimesheet([
        'unpaid_leave_days' => 0,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('BASIC', 5000),
    ]);

    $result = (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);

    expect($result['gross_salary'])->toBe('5000.00')
        ->and($result['net_salary'])->toBe('5000.00')
        ->and($result['leave_days'])->toBe(0.0)
        ->and($result['unpaid_leave_deduction'])->toBe('0.00');
});

test('crew monthly payroll calculator deducts unpaid leave exactly once across unpaid day counts', function (float $unpaidLeaveDays, string $expectedBasic, string $expectedUnpaidDeduction, string $expectedNet) {
    $timesheet = new CrewTimesheet([
        'unpaid_leave_days' => $unpaidLeaveDays,
        'additional_amount' => 0,
        'deduction_amount' => 0,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('BASIC', 3000),
    ]);

    $result = (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);

    expect($result['basic_salary'])->toBe($expectedBasic)
        ->and($result['unpaid_leave_deduction'])->toBe($expectedUnpaidDeduction)
        ->and($result['gross_salary'])->toBe($expectedBasic)
        ->and($result['total_deductions'])->toBe('0.00')
        ->and($result['net_salary'])->toBe($expectedNet);
})->with([
    'no unpaid leave' => [0, '3000.00', '0.00', '3000.00'],
    'one unpaid day' => [1, '2900.00', '100.00', '2900.00'],
    'three unpaid days' => [3, '2700.00', '300.00', '2700.00'],
    'fifteen unpaid days' => [15, '1500.00', '1500.00', '1500.00'],
    'full period unpaid leave' => [30, '0.00', '3000.00', '0.00'],
]);

test('crew monthly payroll calculator still applies other deductions after unpaid leave proration', function () {
    $timesheet = new CrewTimesheet([
        'unpaid_leave_days' => 3,
        'additional_amount' => 0,
        'deduction_amount' => 200,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('BASIC', 3000),
    ]);

    $result = (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);

    expect($result['basic_salary'])->toBe('2700.00')
        ->and($result['unpaid_leave_deduction'])->toBe('300.00')
        ->and($result['other_deductions'])->toBe('200.00')
        ->and($result['total_deductions'])->toBe('200.00')
        ->and($result['net_salary'])->toBe('2500.00');
});

function makeMonthlyCalculatorComponent(string $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code,
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
