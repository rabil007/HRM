<?php

use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewOvertimePay;
use App\Support\Payroll\CrewPayrollCalculator;
use Illuminate\Support\Collection;

test('daily calculator sums sign-on and sign-off standby without double counting', function () {
    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 2,
        'sign_off_standby_days' => 3,
        'onsite_days' => 10,
        'overtime_hours' => 0,
    ]);

    $components = Collection::make([
        makeCrewOpsCalculatorComponent(SalaryComponentCode::Basic, 150),
        makeCrewOpsCalculatorComponent(SalaryComponentCode::SiteAllowance, 50),
        makeCrewOpsCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 75),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['calculation_breakdown']['sign_on_standby_days'])->toBe(2.0)
        ->and($result['calculation_breakdown']['sign_off_standby_days'])->toBe(3.0)
        ->and($result['calculation_breakdown']['total_standby_days'])->toBe(5.0)
        ->and($result['calculation_breakdown']['lines']['sign_on_standby_pay'])->toBe(450.0)
        ->and($result['calculation_breakdown']['lines']['sign_off_standby_pay'])->toBe(675.0)
        ->and($result['calculation_breakdown']['lines']['total_standby_pay'])->toBe(1125.0)
        ->and($result['calculation_breakdown']['lines']['onsite_pay'])->toBe(1500.0)
        ->and($result['gross_salary'])->toBe('3875.00');
});

test('daily calculator applies the same split structure for manual timesheets', function () {
    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 3,
        'sign_off_standby_days' => 0,
        'onsite_days' => 7,
        'overtime_hours' => 0,
    ]);

    $components = Collection::make([
        makeCrewOpsCalculatorComponent(SalaryComponentCode::Basic, 100),
        makeCrewOpsCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 50),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['calculation_breakdown']['lines']['total_standby_pay'])->toBe(450.0)
        ->and($result['calculation_breakdown']['lines']['onsite_pay'])->toBe(700.0)
        ->and($result['calculation_breakdown']['total_standby_days'])->toBe(3.0);
});

function makeCrewOpsCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
