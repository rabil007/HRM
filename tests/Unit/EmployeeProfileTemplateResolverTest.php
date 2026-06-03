<?php

use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;

test('defaults expose all tabs and require name and employee number', function () {
    $resolved = EmployeeProfileTemplateResolver::defaults();

    expect($resolved['employee_tabs']['personal'])->toBeTrue()
        ->and($resolved['employee_tabs']['contract'])->toBeTrue()
        ->and($resolved['employee_tabs']['bank'])->toBeTrue()
        ->and($resolved['fields']['employees'])->not->toHaveKey('user_id')
        ->and($resolved['fields']['employees']['name']['required'])->toBeTrue()
        ->and($resolved['fields']['employees']['employee_no']['required'])->toBeTrue()
        ->and($resolved['fields']['employee_sea_services']['vessel_name']['required'])->toBeTrue()
        ->and($resolved['fields']['employee_education_qualifications']['certificate']['required'])->toBeTrue();
});

test('template can hide bank tab and fields', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['tabs']['bank']['visible'] = false;
    $configuration['fields']['employee_bank_accounts']['iban']['visible'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['employee_tabs']['personal'])->toBeTrue()
        ->and($resolved['employee_tabs']['bank'])->toBeFalse();
});

test('template fields reflect hidden contract_type on employee contracts', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_contracts']['contract_type']['visible'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['employee_tabs']['template_fields']['employee_contracts']['contract_type']['visible'])
        ->toBeFalse()
        ->and($resolved['employee_tabs']['template_fields']['employee_contracts']['start_date']['visible'])
        ->toBeTrue();
});

test('normalize for storage drops legacy linked user field from stored configuration', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['user_id'] = [
        'visible' => true,
        'required' => true,
    ];

    $normalized = EmployeeProfileTemplateResolver::normalizeForStorage($configuration);

    expect($normalized['fields']['employees'])->not->toHaveKey('user_id');
});

test('personal tab visibility is always true when stored false', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['tabs']['personal']['visible'] = false;

    $normalized = EmployeeProfileTemplateResolver::normalizeForStorage($configuration);

    expect($normalized['tabs']['personal']['visible'])->toBeTrue();
});
