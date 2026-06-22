<?php

use App\Enums\PayrollCategory;
use App\Enums\SalaryComponentCode;
use App\Enums\SalaryComponentRateType;
use App\Support\Payroll\ContractSalaryComponentCatalog;

test('office catalog exposes monthly salary components', function () {
    $definitions = ContractSalaryComponentCatalog::definitionsFor(PayrollCategory::Office);

    expect($definitions)->toHaveCount(4)
        ->and(collect($definitions)->pluck('component_code')->all())->toBe([
            SalaryComponentCode::Basic,
            SalaryComponentCode::Housing,
            SalaryComponentCode::Transport,
            SalaryComponentCode::Other,
        ])
        ->and($definitions[0]['rate_type'])->toBe(SalaryComponentRateType::Monthly->value);
});

test('crew catalog exposes daily basic and hourly ot components', function () {
    $definitions = ContractSalaryComponentCatalog::definitionsFor(PayrollCategory::Crew);

    expect($definitions)->toHaveCount(4)
        ->and(collect($definitions)->pluck('component_code')->all())->toContain(
            SalaryComponentCode::Basic,
            SalaryComponentCode::OtRate,
        );

    $basicDefinition = collect($definitions)->firstWhere(
        'component_code',
        SalaryComponentCode::Basic,
    );

    $otDefinition = collect($definitions)->firstWhere(
        'component_code',
        SalaryComponentCode::OtRate,
    );

    expect($basicDefinition['rate_type'])->toBe(SalaryComponentRateType::Daily->value)
        ->and($otDefinition['rate_type'])->toBe(SalaryComponentRateType::Hourly->value);
});

test('legacy column map differs by payroll category', function () {
    expect(ContractSalaryComponentCatalog::legacyColumnMap(PayrollCategory::Office))
        ->toHaveKey('basic_salary', SalaryComponentCode::Basic)
        ->and(ContractSalaryComponentCatalog::legacyColumnMap(PayrollCategory::Crew))
        ->toHaveKey('basic_salary', SalaryComponentCode::Basic)
        ->toHaveKey('site_allowance', SalaryComponentCode::SiteAllowance);
});
