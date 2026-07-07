<?php

use App\Enums\ContractSalaryStructure;
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

test('crew daily catalog exposes daily rate components only', function () {
    $definitions = ContractSalaryComponentCatalog::definitionsFor(
        PayrollCategory::Crew,
        ContractSalaryStructure::Daily,
    );

    expect($definitions)->toHaveCount(3)
        ->and(collect($definitions)->pluck('component_code')->all())->toBe([
            SalaryComponentCode::Basic,
            SalaryComponentCode::SiteAllowance,
            SalaryComponentCode::SupplementaryAllowance,
        ]);

    $basicDefinition = collect($definitions)->firstWhere(
        'component_code',
        SalaryComponentCode::Basic,
    );

    expect($basicDefinition['rate_type'])->toBe(SalaryComponentRateType::Daily->value);
});

test('crew monthly catalog exposes monthly salary components', function () {
    $definitions = ContractSalaryComponentCatalog::definitionsFor(
        PayrollCategory::Crew,
        ContractSalaryStructure::Monthly,
    );

    expect($definitions)->toHaveCount(4)
        ->and(collect($definitions)->pluck('component_code')->all())->toBe([
            SalaryComponentCode::Basic,
            SalaryComponentCode::Housing,
            SalaryComponentCode::Transport,
            SalaryComponentCode::Other,
        ])
        ->and($definitions[0]['rate_type'])->toBe(SalaryComponentRateType::Monthly->value);
});

test('legacy column map differs by payroll category and crew salary structure', function () {
    expect(ContractSalaryComponentCatalog::legacyColumnMap(PayrollCategory::Office))
        ->toHaveKey('basic_salary', SalaryComponentCode::Basic)
        ->and(ContractSalaryComponentCatalog::legacyColumnMap(PayrollCategory::Crew, ContractSalaryStructure::Daily))
        ->toHaveKey('basic_salary', SalaryComponentCode::Basic)
        ->toHaveKey('site_allowance', SalaryComponentCode::SiteAllowance)
        ->and(ContractSalaryComponentCatalog::legacyColumnMap(PayrollCategory::Crew, ContractSalaryStructure::Monthly))
        ->toHaveKey('housing_allowance', SalaryComponentCode::Housing);
});
