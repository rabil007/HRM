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

test('allocation plan path pays prior and current days at plan rates while OT uses payment-period components', function () {
    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 0,
        'onsite_days' => 0,
        'overtime_hours' => 98,
        'additional_amount' => 25,
        'deduction_amount' => 10,
    ]);
    $timesheet->setRelation('segments', collect());

    // Payment-period rates (July) — used for OT only.
    $components = Collection::make([
        makeAllocationPlanComponent(SalaryComponentCode::Basic, 33.5),
        makeAllocationPlanComponent(SalaryComponentCode::SiteAllowance, 250),
        makeAllocationPlanComponent(SalaryComponentCode::SupplementaryAllowance, 66.5),
    ]);

    $allocationPlan = [
        'days' => [
            [
                'work_date' => '2026-06-25',
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'period_classification' => PayrollWorkPeriodClassification::Prior->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 180.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 0.0,
                'basic_amount' => 180.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 0.0,
                'total_amount' => 180.0,
                'crew_timesheet_segment_id' => 1,
            ],
            [
                'work_date' => '2026-07-01',
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'period_classification' => PayrollWorkPeriodClassification::Current->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 220.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 0.0,
                'basic_amount' => 220.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 0.0,
                'total_amount' => 220.0,
                'crew_timesheet_segment_id' => 1,
            ],
            [
                'work_date' => '2026-07-02',
                'pay_category' => CrewTimesheetPayCategory::SignOnStandby->value,
                'period_classification' => PayrollWorkPeriodClassification::Current->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 220.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 50.0,
                'basic_amount' => 220.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 50.0,
                'total_amount' => 270.0,
                'crew_timesheet_segment_id' => 2,
            ],
        ],
        'earning_periods' => [
            [
                'from_date' => '2026-06-25',
                'to_date' => '2026-06-25',
                'days' => 1,
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'period_classification' => PayrollWorkPeriodClassification::Prior->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 180.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 0.0,
                'amount' => 180.0,
                'basic_amount' => 180.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 0.0,
            ],
            [
                'from_date' => '2026-07-01',
                'to_date' => '2026-07-01',
                'days' => 1,
                'pay_category' => CrewTimesheetPayCategory::Onsite->value,
                'period_classification' => PayrollWorkPeriodClassification::Current->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 220.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 0.0,
                'amount' => 220.0,
                'basic_amount' => 220.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 0.0,
            ],
            [
                'from_date' => '2026-07-02',
                'to_date' => '2026-07-02',
                'days' => 1,
                'pay_category' => CrewTimesheetPayCategory::SignOnStandby->value,
                'period_classification' => PayrollWorkPeriodClassification::Current->value,
                'contract_id' => 1,
                'salary_revision_id' => null,
                'basic_daily_rate' => 220.0,
                'site_allowance_daily_rate' => 0.0,
                'supplementary_allowance_daily_rate' => 50.0,
                'amount' => 270.0,
                'basic_amount' => 220.0,
                'site_allowance_amount' => 0.0,
                'supplementary_allowance_amount' => 50.0,
            ],
        ],
        'requested_prior_days' => 1,
        'payable_prior_days' => 1,
        'current_days' => 2,
        'excluded_already_paid' => [],
        'reserved_conflicts' => [],
        'warnings' => [],
        'issues' => [],
    ];

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        31,
        $allocationPlan,
    );

    // Allocation: 180 + 220 + 270 = 670; OT (payment-period rates) = 3523.97; +25 -10
    // basic_salary = sum of basic_amount = 180+220+220 = 620
    // other_allowances = site + supp = 0+50 = 50
    expect($result['basic_salary'])->toBe('620.00')
        ->and($result['other_allowances'])->toBe('50.00')
        ->and($result['overtime_pay'])->toBe('3523.97')
        ->and($result['present_days'])->toBe(2.0)
        ->and($result['leave_days'])->toBe(29.0)
        ->and($result['gross_salary'])->toBe('4218.97')
        ->and($result['net_salary'])->toBe('4208.97')
        ->and($result['calculation_breakdown']['onsite_days'])->toBe(1.0)
        ->and($result['calculation_breakdown']['sign_on_standby_days'])->toBe(1.0)
        ->and($result['calculation_breakdown']['sign_on_standby_pay'])->toBe(270.0)
        ->and($result['calculation_breakdown']['current_period']['amount'])->toBe(490.0)
        ->and($result['calculation_breakdown']['prior_period']['amount'])->toBe(180.0)
        ->and($result['calculation_breakdown']['prior_period_adjustments'])->toHaveCount(1)
        ->and($result['calculation_breakdown']['earning_periods'])->toHaveCount(3)
        ->and($result['calculation_breakdown']['rates']['basic_daily'])->toBe(33.5)
        ->and($result['calculation_breakdown']['overtime']['monthly_salary'])->toBe(10500.0);
});

test('allocation plan path without plan argument still uses legacy timesheet formulas', function () {
    $timesheet = new CrewTimesheet([
        'sign_on_standby_days' => 5,
        'onsite_days' => 10,
        'overtime_hours' => 0,
        'additional_amount' => 100,
        'deduction_amount' => 50,
    ]);

    $components = Collection::make([
        makeAllocationPlanComponent(SalaryComponentCode::Basic, 150),
        makeAllocationPlanComponent(SalaryComponentCode::SiteAllowance, 50),
        makeAllocationPlanComponent(SalaryComponentCode::SupplementaryAllowance, 75),
    ]);

    $result = (new CrewPayrollCalculator(new CrewOvertimePay))->calculate(
        $timesheet,
        $components,
        30,
        30,
    );

    expect($result['gross_salary'])->toBe('3975.00')
        ->and($result['net_salary'])->toBe('3925.00')
        ->and($result['calculation_breakdown'])->not->toHaveKey('prior_period_adjustments');
});

function makeAllocationPlanComponent(SalaryComponentCode $code, float $amount): ContractSalaryComponent
{
    return new ContractSalaryComponent([
        'component_code' => $code,
        'component_name' => $code->label(),
        'amount' => $amount,
        'status' => SalaryComponentStatus::Active,
    ]);
}
