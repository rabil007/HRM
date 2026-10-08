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
        ->and($resolved['fields']['employee_sea_services']['vessel_id']['required'])->toBeTrue()
        ->and($resolved['fields']['employee_education_qualifications']['certificate']['required'])->toBeTrue()
        ->and($resolved['fields']['employee_contracts'])->toHaveKey('payroll_category')
        ->and($resolved['fields']['employee_contracts']['payroll_category']['visible'])->toBeTrue();
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

test('template fields reflect hidden end_date on employee contracts', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_contracts']['end_date']['visible'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['employee_tabs']['template_fields']['employee_contracts']['end_date']['visible'])
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

test('employee number remains visible and required even when stored as optional or hidden', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['employee_no'] = [
        'visible' => false,
        'required' => false,
    ];
    $configuration['fields']['employees']['name'] = [
        'visible' => false,
        'required' => false,
    ];

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);
    $normalized = EmployeeProfileTemplateResolver::normalizeForStorage($configuration);

    expect($resolved['fields']['employees']['employee_no']['visible'])->toBeTrue()
        ->and($resolved['fields']['employees']['employee_no']['required'])->toBeTrue()
        ->and($resolved['fields']['employees']['name']['visible'])->toBeTrue()
        ->and($resolved['fields']['employees']['name']['required'])->toBeTrue()
        ->and($normalized['fields']['employees']['employee_no']['visible'])->toBeTrue()
        ->and($normalized['fields']['employees']['employee_no']['required'])->toBeTrue();
});

test('defaults expose position_id once and do not advertise rank_id', function () {
    $defaults = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $labels = EmployeeProfileTemplateFieldRegistry::fieldsByTable();

    expect($labels['employees'])->toHaveKey('position_id')
        ->and($labels['employees'])->not->toHaveKey('rank_id')
        ->and($labels['employee_sea_services'])->toHaveKey('position_id')
        ->and($labels['employee_sea_services'])->not->toHaveKey('rank_id')
        ->and($defaults['fields']['employees'])->toHaveKey('position_id')
        ->and($defaults['fields']['employees'])->not->toHaveKey('rank_id')
        ->and($defaults['fields']['employee_sea_services']['position_id']['required'])->toBeTrue()
        ->and($defaults['fields']['employee_sea_services'])->not->toHaveKey('rank_id');
});

test('legacy employees rank_id config resolves onto position_id', function () {
    $template = new EmployeeProfileTemplate([
        'configuration_json' => [
            'version' => 1,
            'tabs' => [],
            'fields' => [
                'employees' => [
                    'rank_id' => [
                        'visible' => false,
                        'required' => true,
                    ],
                ],
            ],
        ],
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['fields']['employees']['position_id']['visible'])->toBeFalse()
        ->and($resolved['fields']['employees']['position_id']['required'])->toBeTrue()
        ->and($resolved['fields']['employees'])->not->toHaveKey('rank_id');
});

test('employees position_id wins when both legacy and canonical keys exist', function () {
    $template = new EmployeeProfileTemplate([
        'configuration_json' => [
            'version' => 1,
            'tabs' => [],
            'fields' => [
                'employees' => [
                    'rank_id' => [
                        'visible' => false,
                        'required' => false,
                    ],
                    'position_id' => [
                        'visible' => true,
                        'required' => true,
                    ],
                ],
            ],
        ],
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['fields']['employees']['position_id']['visible'])->toBeTrue()
        ->and($resolved['fields']['employees']['position_id']['required'])->toBeTrue()
        ->and($resolved['fields']['employees'])->not->toHaveKey('rank_id');
});

test('legacy employee_sea_services rank_id required resolves onto position_id', function () {
    $template = new EmployeeProfileTemplate([
        'configuration_json' => [
            'version' => 1,
            'tabs' => [],
            'fields' => [
                'employee_sea_services' => [
                    'rank_id' => [
                        'visible' => true,
                        'required' => true,
                    ],
                ],
            ],
        ],
    ]);

    $resolved = EmployeeProfileTemplateResolver::resolve($template);

    expect($resolved['fields']['employee_sea_services']['position_id']['visible'])->toBeTrue()
        ->and($resolved['fields']['employee_sea_services']['position_id']['required'])->toBeTrue()
        ->and($resolved['fields']['employee_sea_services'])->not->toHaveKey('rank_id');
});

test('normalize for storage persists position_id and drops legacy rank_id', function () {
    $normalized = EmployeeProfileTemplateResolver::normalizeForStorage([
        'version' => 1,
        'tabs' => [],
        'fields' => [
            'employees' => [
                'rank_id' => [
                    'visible' => false,
                    'required' => true,
                ],
            ],
            'employee_sea_services' => [
                'rank_id' => [
                    'visible' => true,
                    'required' => false,
                ],
            ],
        ],
    ]);

    expect($normalized['fields']['employees']['position_id']['visible'])->toBeFalse()
        ->and($normalized['fields']['employees']['position_id']['required'])->toBeTrue()
        ->and($normalized['fields']['employees'])->not->toHaveKey('rank_id')
        ->and($normalized['fields']['employee_sea_services']['position_id']['required'])->toBeFalse()
        ->and($normalized['fields']['employee_sea_services'])->not->toHaveKey('rank_id');
});
