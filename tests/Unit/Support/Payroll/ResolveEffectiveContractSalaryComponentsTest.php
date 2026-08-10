<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentStatus;
use App\Models\ContractSalaryComponent;
use App\Models\ContractSalaryRevision;
use App\Models\ContractSalaryRevisionLine;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\ResolveEffectiveContractSalaryComponents;
use Illuminate\Support\Carbon;

test('resolve picks the latest revision effective on or before as of date', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
        'status' => 'active',
    ]);

    $apply = app(ApplyContractSalaryRevision::class);
    $apply->handle($contract, [
        'basic_salary' => 100,
        'site_allowance' => 50,
        'supplementary_allowance' => 25,
    ], '2026-01-01', 'v1');

    $apply->handle($contract->fresh(), [
        'basic_salary' => 120,
        'site_allowance' => 80,
        'supplementary_allowance' => 30,
    ], '2026-03-01', 'v2');

    $resolver = app(ResolveEffectiveContractSalaryComponents::class);

    $january = $resolver->handle($contract->fresh(), Carbon::parse('2026-01-15'));
    $siteJanuary = $january->first(
        fn (ContractSalaryComponent $component) => $component->component_code === SalaryComponentCode::SiteAllowance
            && $component->status === SalaryComponentStatus::Active,
    );

    expect($siteJanuary)->not->toBeNull()
        ->and((float) $siteJanuary->amount)->toBe(50.0);

    $march = $resolver->handle($contract->fresh(), Carbon::parse('2026-03-01'));
    $siteMarch = $march->first(
        fn (ContractSalaryComponent $component) => $component->component_code === SalaryComponentCode::SiteAllowance
            && $component->status === SalaryComponentStatus::Active,
    );

    expect($siteMarch)->not->toBeNull()
        ->and((float) $siteMarch->amount)->toBe(80.0);
});

test('resolve falls back to contract salary components when no revisions exist', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 9000,
        'status' => 'active',
    ]);

    ContractSalaryComponent::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'component_code' => SalaryComponentCode::Basic,
        'component_name' => SalaryComponentCode::Basic->label(),
        'amount' => 9000,
        'status' => SalaryComponentStatus::Active,
    ]);

    $components = app(ResolveEffectiveContractSalaryComponents::class)
        ->handle($contract->fresh()->load('salaryComponents'), Carbon::parse('2026-06-01'));

    $basic = $components->first(
        fn (ContractSalaryComponent $component) => $component->component_code === SalaryComponentCode::Basic,
    );

    expect($basic)->not->toBeNull()
        ->and((float) $basic->amount)->toBe(9000.0);
});

test('resolve returns a structured error when only a future revision exists', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 9000,
        'status' => 'active',
    ]);

    ContractSalaryComponent::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'component_code' => SalaryComponentCode::Basic,
        'amount' => 9000,
        'status' => SalaryComponentStatus::Active,
    ]);

    $revision = ContractSalaryRevision::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'employee_id' => $contract->employee_id,
        'effective_from' => '2026-08-01',
    ]);

    ContractSalaryRevisionLine::factory()->create([
        'company_id' => $company->id,
        'revision_id' => $revision->id,
        'component_code' => SalaryComponentCode::Basic,
        'amount' => 10000,
    ]);

    $resolved = app(ResolveEffectiveContractSalaryComponents::class)
        ->resolve($contract->fresh(['salaryComponents']), Carbon::parse('2026-07-01'));

    expect($resolved['components'])->toBeEmpty()
        ->and($resolved['revision'])->toBeNull()
        ->and($resolved['has_revision_history'])->toBeTrue()
        ->and($resolved['issue']['code'])->toBe('missing_historical_salary_revision');
});

test('soft deleted revision history never reactivates legacy fallback', function () {
    ['company' => $company] = makePayrollFixtures();

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Office,
        'basic_salary' => 9000,
        'status' => 'active',
    ]);

    ContractSalaryComponent::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'component_code' => SalaryComponentCode::Basic,
        'amount' => 9000,
        'status' => SalaryComponentStatus::Active,
    ]);

    $revision = ContractSalaryRevision::factory()->create([
        'company_id' => $company->id,
        'contract_id' => $contract->id,
        'employee_id' => $contract->employee_id,
        'effective_from' => '2026-01-01',
    ]);
    $revision->delete();

    $resolved = app(ResolveEffectiveContractSalaryComponents::class)
        ->resolve($contract->fresh(['salaryComponents']), Carbon::parse('2026-07-01'));

    expect($resolved['components'])->toBeEmpty()
        ->and($resolved['revision'])->toBeNull()
        ->and($resolved['issue']['code'])->toBe('missing_historical_salary_revision');
});
