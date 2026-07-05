<?php

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewOvertimePay;
use App\Support\Payroll\CrewPayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('crew payroll calculator applies standby onsite allowance and adjustment formulas', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 5,
        'onsite_days' => 10,
        'overtime_hours' => 0,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 150),
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
        makeCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 75),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        8040,
    );

    expect($result['calculation_breakdown']['lines'])->toMatchArray([
        'standby_pay' => 1125.0,
        'onsite_pay' => 1500.0,
        'site_allowance' => 500.0,
        'supplementary_allowance' => 750.0,
        'overtime' => 0.0,
        'additional' => 100.0,
        'deduction' => 50.0,
    ])
        ->and($result['gross_salary'])->toBe('3975.00')
        ->and($result['net_salary'])->toBe('3925.00')
        ->and($result['overtime_pay'])->toBe('0.00');
});

test('crew payroll calculator calculates overtime pay from hours at processing time', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 10,
        'overtime_hours' => 76,
        'additional_amount' => 0,
        'deduction_amount' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 150),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        8040,
    );

    expect($result['overtime_pay'])->toBe('2092.60')
        ->and($result['overtime_hours'])->toBe(76.0)
        ->and($result['calculation_breakdown']['overtime']['overtime_pay'])->toBe(2092.60);
});

test('crew payroll calculator requires overtime monthly salary when hours are entered', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 10,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 150),
    ]);

    (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        null,
    );
})->throws(ValidationException::class);

test('crew payroll calculator requires an active basic daily rate', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 5,
        'onsite_days' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
    ]);

    (new CrewPayrollCalculator(new CrewOvertimePay))->calculate($timesheet, $components);
})->throws(ValidationException::class);

test('crew payroll calculator includes supplementary allowance on standby days', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 2,
        'onsite_days' => 15,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 50),
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 661),
        makeCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 611),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate($timesheet, $components);

    expect($result['calculation_breakdown']['lines'])->toMatchArray([
        'standby_pay' => 1322.0,
        'onsite_pay' => 750.0,
        'site_allowance' => 9915.0,
        'supplementary_allowance' => 9165.0,
    ])
        ->and($result['gross_salary'])->toBe('21152.00')
        ->and($result['net_salary'])->toBe('21152.00');
});

function makeCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
