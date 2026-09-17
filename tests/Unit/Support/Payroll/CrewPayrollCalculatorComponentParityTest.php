<?php

use App\Enums\CrewTimesheetPayCategory;
use App\Enums\PayrollWorkPeriodClassification;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\CrewTimesheet;
use App\Support\Payroll\CrewOvertimePay;
use App\Support\Payroll\CrewPayrollCalculator;
use Illuminate\Support\Collection;

test('daily crew timesheet and allocation plan paths classify standby components identically', function () {
    $components = Collection::make([
        makeParityCalculatorComponent(SalaryComponentCode::Basic, 100),
        makeParityCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 20),
    ]);

    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 3,
        'sign_off_standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 0,
        'additional_amount' => 0,
        'deduction_amount' => 0,
    ]);
    $timesheet->setRelation('segments', collect());

    $timesheetResult = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    $allocationPlan = [
        'days' => [
            makeParityAllocationDay('2026-07-01', CrewTimesheetPayCategory::SignOnStandby, 100, 0, 20),
            makeParityAllocationDay('2026-07-02', CrewTimesheetPayCategory::SignOnStandby, 100, 0, 20),
            makeParityAllocationDay('2026-07-03', CrewTimesheetPayCategory::SignOnStandby, 100, 0, 20),
        ],
        'earning_periods' => [],
        'requested_prior_days' => 0,
        'payable_prior_days' => 0,
        'current_days' => 3,
        'excluded_already_paid' => [],
        'reserved_conflicts' => [],
        'warnings' => [],
        'issues' => [],
    ];

    $allocationResult = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
        $allocationPlan,
    );

    expect($allocationResult['basic_salary'])->toBe($timesheetResult['basic_salary'])
        ->and($allocationResult['other_allowances'])->toBe($timesheetResult['other_allowances'])
        ->and($allocationResult['gross_salary'])->toBe($timesheetResult['gross_salary'])
        ->and($allocationResult['net_salary'])->toBe($timesheetResult['net_salary'])
        ->and($timesheetResult['basic_salary'])->toBe('300.00')
        ->and($timesheetResult['other_allowances'])->toBe('60.00')
        ->and($timesheetResult['gross_salary'])->toBe('360.00');
});

test('daily crew timesheet and allocation plan paths classify onsite components identically', function () {
    $components = Collection::make([
        makeParityCalculatorComponent(SalaryComponentCode::Basic, 100),
        makeParityCalculatorComponent(SalaryComponentCode::SiteAllowance, 30),
        makeParityCalculatorComponent(SalaryComponentCode::SupplementaryAllowance, 20),
    ]);

    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 0,
        'sign_off_standby_days' => 0,
        'onsite_days' => 3,
        'overtime_hours' => 0,
        'additional_amount' => 0,
        'deduction_amount' => 0,
    ]);
    $timesheet->setRelation('segments', collect());

    $timesheetResult = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    $allocationPlan = [
        'days' => [
            makeParityAllocationDay('2026-07-01', CrewTimesheetPayCategory::Onsite, 100, 30, 20),
            makeParityAllocationDay('2026-07-02', CrewTimesheetPayCategory::Onsite, 100, 30, 20),
            makeParityAllocationDay('2026-07-03', CrewTimesheetPayCategory::Onsite, 100, 30, 20),
        ],
        'earning_periods' => [],
        'requested_prior_days' => 0,
        'payable_prior_days' => 0,
        'current_days' => 3,
        'excluded_already_paid' => [],
        'reserved_conflicts' => [],
        'warnings' => [],
        'issues' => [],
    ];

    $allocationResult = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
        $allocationPlan,
    );

    expect($allocationResult['basic_salary'])->toBe($timesheetResult['basic_salary'])
        ->and($allocationResult['other_allowances'])->toBe($timesheetResult['other_allowances'])
        ->and($allocationResult['gross_salary'])->toBe($timesheetResult['gross_salary'])
        ->and($allocationResult['net_salary'])->toBe($timesheetResult['net_salary'])
        ->and($timesheetResult['basic_salary'])->toBe('300.00')
        ->and($timesheetResult['other_allowances'])->toBe('150.00')
        ->and($timesheetResult['gross_salary'])->toBe('450.00');
});

function makeParityCalculatorComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}

function makeParityAllocationDay(
    string $workDate,
    CrewTimesheetPayCategory $payCategory,
    float $basicRate,
    float $siteRate,
    float $supplementaryRate,
): array {
    $siteAmount = $payCategory === CrewTimesheetPayCategory::Onsite ? $siteRate : 0.0;
    $supplementaryAmount = round($supplementaryRate, 2);

    return [
        'work_date' => $workDate,
        'pay_category' => $payCategory->value,
        'period_classification' => PayrollWorkPeriodClassification::Current->value,
        'contract_id' => 1,
        'salary_revision_id' => null,
        'basic_daily_rate' => $basicRate,
        'site_allowance_daily_rate' => $siteRate,
        'supplementary_allowance_daily_rate' => $supplementaryRate,
        'basic_amount' => round($basicRate, 2),
        'site_allowance_amount' => round($siteAmount, 2),
        'supplementary_allowance_amount' => $supplementaryAmount,
        'total_amount' => round($basicRate + $siteAmount + $supplementaryAmount, 2),
        'crew_timesheet_segment_id' => 1,
    ];
}
