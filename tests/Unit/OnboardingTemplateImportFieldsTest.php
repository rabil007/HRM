<?php

use App\Support\OnboardingTemplateImportFields;

test('version 2 template with empty bank fields omits bank columns from import', function () {
    $columns = OnboardingTemplateImportFields::columnsForTasks([
        'version' => 2,
        'stages' => [
            [
                'key' => 'profile',
                'employee_fields' => [
                    ['key' => 'employee_no'],
                    ['key' => 'name'],
                    ['key' => 'work_email'],
                ],
                'bank_account_fields' => [],
                'contract_fields' => [
                    ['key' => 'contract_type'],
                    ['key' => 'start_date'],
                ],
            ],
        ],
    ]);

    expect($columns)->toContain('employee_no', 'name', 'work_email', 'contract_type', 'start_date', 'status')
        ->and($columns)->not->toContain('bank', 'iban', 'account_name');
});

test('version 2 template without field group keys includes default import columns for that group', function () {
    $columns = OnboardingTemplateImportFields::columnsForTasks([
        'version' => 2,
        'stages' => [
            [
                'key' => 'profile',
                'employee_fields' => [
                    ['key' => 'name'],
                ],
            ],
        ],
    ]);

    expect($columns)->toContain('name', 'bank', 'iban', 'contract_type', 'start_date')
        ->and($columns)->not->toContain('cv_source');
});
