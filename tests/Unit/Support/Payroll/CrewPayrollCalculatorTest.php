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
        30,
        30,
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

test('crew payroll calculator calculates overtime pay from hours and period daily rates', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 98,
        'additional_amount' => 0,
        'deduction_amount' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 33.5),
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 250),
        makeCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 66.5),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['overtime_pay'])->toBe('3523.97')
        ->and($result['overtime_hours'])->toBe(98.0)
        ->and($result['calculation_breakdown']['overtime']['monthly_salary'])->toBe(10500.0)
        ->and($result['calculation_breakdown']['overtime']['period_days'])->toBe(30)
        ->and($result['calculation_breakdown']['overtime']['daily_onsite_rate'])->toBe(350.0);
});

test('crew payroll calculator requires pay period days when overtime hours are entered', function () {
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
        0,
        30,
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

    (new CrewPayrollCalculator(new CrewOvertimePay))->calculate($timesheet, $components, 30, 30);
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

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate($timesheet, $components, 30, 30);

    expect($result['calculation_breakdown']['lines'])->toMatchArray([
        'standby_pay' => 1322.0,
        'onsite_pay' => 750.0,
        'site_allowance' => 9915.0,
        'supplementary_allowance' => 9165.0,
    ])
        ->and($result['gross_salary'])->toBe('21152.00')
        ->and($result['net_salary'])->toBe('21152.00');
});

test('crew payroll calculator returns zero pay and full leave days when employee has no work', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 150),
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['gross_salary'])->toBe('0.00')
        ->and($result['net_salary'])->toBe('0.00')
        ->and($result['working_days'])->toBe(30)
        ->and($result['present_days'])->toBe(0.0)
        ->and($result['leave_days'])->toBe(30.0);
});

test('crew payroll calculator allows missing basic daily rate when there is no payable activity', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['gross_salary'])->toBe('0.00')
        ->and($result['leave_days'])->toBe(30.0)
        ->and($result['calculation_breakdown']['rates']['basic_daily'])->toBe(0.0);
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
