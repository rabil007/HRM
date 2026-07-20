<?php

use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewMonthlyPayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('crew monthly payroll calculator prorates monthly components from working and leave days', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 5,
        'onsite_days' => 25,
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
        ->and($result['net_salary'])->toBe('5716.67');
});

test('crew monthly payroll calculator requires basic monthly salary when there is payable activity', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 2,
        'onsite_days' => 0,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('HOUSING', 2000),
    ]);

    (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);
})->throws(ValidationException::class);

test('crew monthly payroll calculator returns zero pay when there is no activity', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 0,
    ]);

    $components = Collection::make([
        makeMonthlyCalculatorComponent('BASIC', 5000),
    ]);

    $result = (new CrewMonthlyPayrollCalculator)->calculate($timesheet, $components, 30);

    expect($result['gross_salary'])->toBe('0.00')
        ->and($result['net_salary'])->toBe('0.00')
        ->and($result['leave_days'])->toBe(0.0);
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
