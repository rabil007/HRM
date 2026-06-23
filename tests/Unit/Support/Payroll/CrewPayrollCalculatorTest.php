<?php

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewPayrollCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

test('crew payroll calculator applies standby onsite allowance and adjustment formulas', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 5,
        'onsite_days' => 10,
        'overtime_amount' => 200,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::Basic, 150),
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
        makeCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 75),
    ]);

    $result = (new CrewPayrollCalculator)->calculate($timesheet, $components);

    expect($result['calculation_breakdown']['lines'])->toMatchArray([
        'standby_pay' => 750.0,
        'onsite_pay' => 1500.0,
        'site_allowance' => 500.0,
        'supplementary_allowance' => 750.0,
        'overtime' => 200.0,
        'additional' => 100.0,
        'deduction' => 50.0,
    ])
        ->and($result['gross_salary'])->toBe('3800.00')
        ->and($result['net_salary'])->toBe('3750.00')
        ->and($result['basic_salary'])->toBe('2250.00')
        ->and($result['other_allowances'])->toBe('1250.00')
        ->and($result['overtime_pay'])->toBe('200.00')
        ->and($result['bonus'])->toBe('100.00')
        ->and($result['other_deductions'])->toBe('50.00');
});

test('crew payroll calculator requires an active basic daily rate', function () {
    $timesheet = new CrewTimesheet([
        'standby_days' => 5,
        'onsite_days' => 0,
    ]);

    $components = Collection::make([
        makeCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
    ]);

    (new CrewPayrollCalculator)->calculate($timesheet, $components);
})->throws(ValidationException::class);

function makeCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
