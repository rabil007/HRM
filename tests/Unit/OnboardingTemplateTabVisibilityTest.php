<?php

use App\Support\OnboardingTemplateTabVisibility;

it('defaults to all tabs visible when tasks are null', function () {
    $tabs = OnboardingTemplateTabVisibility::fromTasks(null);

    expect($tabs['personal'])->toBeTrue()
        ->and($tabs['contract'])->toBeTrue()
        ->and($tabs['bank'])->toBeTrue()
        ->and($tabs['documents'])->toBeTrue()
        ->and($tabs['sea_service'])->toBeTrue()
        ->and($tabs['vaccination'])->toBeTrue();
});

it('aggregates version 2 visibility from non-empty field groups', function () {
    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'a',
                'label' => 'A',
                'employee_fields' => [['key' => 'name', 'required' => true]],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'documents' => [],
            ],
            [
                'key' => 'b',
                'label' => 'B',
                'employee_fields' => [],
                'bank_account_fields' => [['key' => 'iban', 'required' => false]],
                'contract_fields' => [['key' => 'start_date', 'required' => true]],
                'sea_service_fields' => [['key' => 'vessel_type_id', 'required' => true]],
                'vaccination_fields' => [['key' => 'vaccination_name', 'required' => true]],
                'documents' => [['type' => '1', 'min' => 1]],
            ],
        ],
    ];

    $tabs = OnboardingTemplateTabVisibility::fromTasks($tasks);

    expect($tabs['personal'])->toBeTrue()
        ->and($tabs['bank'])->toBeTrue()
        ->and($tabs['contract'])->toBeTrue()
        ->and($tabs['documents'])->toBeTrue()
        ->and($tabs['sea_service'])->toBeTrue()
        ->and($tabs['vaccination'])->toBeTrue();
});

it('hides sea service and vaccination when their keys are absent in every version 2 stage', function () {
    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'a',
                'label' => 'A',
                'employee_fields' => [],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'documents' => [],
            ],
        ],
    ];

    $tabs = OnboardingTemplateTabVisibility::fromTasks($tasks);

    expect($tabs['sea_service'])->toBeFalse()
        ->and($tabs['vaccination'])->toBeFalse();
});

it('hides contract when explicitly empty in every version 2 stage', function () {
    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'a',
                'label' => 'A',
                'employee_fields' => [],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'documents' => [],
            ],
        ],
    ];

    $tabs = OnboardingTemplateTabVisibility::fromTasks($tasks);

    expect($tabs['contract'])->toBeFalse();
});

it('keeps contract visible when contract_fields keys are absent in version 2', function () {
    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'a',
                'label' => 'A',
                'employee_fields' => [],
                'bank_account_fields' => [],
                'documents' => [],
            ],
        ],
    ];

    $tabs = OnboardingTemplateTabVisibility::fromTasks($tasks);

    expect($tabs['contract'])->toBeTrue();
});

it('hides sea service and vaccination when explicitly empty in every version 2 stage', function () {
    $tasks = [
        'version' => 2,
        'stages' => [
            [
                'key' => 'a',
                'label' => 'A',
                'employee_fields' => [],
                'bank_account_fields' => [],
                'contract_fields' => [],
                'sea_service_fields' => [],
                'vaccination_fields' => [],
                'documents' => [],
            ],
        ],
    ];

    $tabs = OnboardingTemplateTabVisibility::fromTasks($tasks);

    expect($tabs['sea_service'])->toBeFalse()
        ->and($tabs['vaccination'])->toBeFalse();
});
