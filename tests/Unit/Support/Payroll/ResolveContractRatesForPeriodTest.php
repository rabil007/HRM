<?php

use App\Enums\PayrollCategory;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\ResolveContractRatesForPeriod;
use Illuminate\Support\Carbon;

test('board rates prefer salary revision effective for the period', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
        'status' => 'active',
    ]);

    $apply = app(ApplyContractSalaryRevision::class);
    $apply->handle($contract, [
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
    ], '2026-01-01', 'v1');

    $apply->handle($contract->fresh(), [
        'basic_salary' => 50,
        'site_allowance' => 860,
        'supplementary_allowance' => 100,
    ], '2026-02-01', 'v2');

    $contract->update([
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
    ]);

    $rates = app(ResolveContractRatesForPeriod::class)
        ->handle($contract->fresh(), Carbon::parse('2026-02-18'));

    expect((float) $rates['basic_salary'])->toBe(50.0)
        ->and((float) $rates['site_allowance'])->toBe(860.0)
        ->and((float) $rates['supplementary_allowance'])->toBe(100.0);
});

test('board rates fall back to contract columns when no revisions exist', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'basic_salary' => 50,
        'site_allowance' => 300,
        'supplementary_allowance' => 50,
        'status' => 'active',
    ]);

    $rates = app(ResolveContractRatesForPeriod::class)
        ->handle($contract->fresh()->load('salaryComponents'), Carbon::parse('2026-02-18'));

    expect((float) $rates['basic_salary'])->toBe(50.0)
        ->and((float) $rates['site_allowance'])->toBe(300.0)
        ->and((float) $rates['supplementary_allowance'])->toBe(50.0);
});
