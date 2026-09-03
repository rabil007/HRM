<?php

use App\Models\User;
use App\Support\Navigation\NavigationDestinationCatalog;

test('destination keys and hrefs are unique', function () {
    $destinations = NavigationDestinationCatalog::all();
    $keys = array_column($destinations, 'key');
    $hrefs = array_column($destinations, 'href');

    expect($keys)->toHaveCount(count(array_unique($keys)))
        ->and($hrefs)->toHaveCount(count(array_unique($hrefs)));
});

test('unknown keys are not accessible', function () {
    $user = User::factory()->create();

    expect(NavigationDestinationCatalog::contains('employee.record.1'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'employees'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'not-a-key'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'dashboard'))->toBeTrue();
});

test('vessel manning cannot unlock the vessels destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessel_manning.view']);

    expect(NavigationDestinationCatalog::contains('crew.vessel-manning'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessels'))->toBeFalse()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessel-manning'))->toBeTrue();
});

test('documents destinations form one unified group without a standalone bulk generate href', function () {
    $documents = array_values(array_filter(
        NavigationDestinationCatalog::all(),
        fn (array $destination): bool => $destination['group'] === 'Documents',
    ));

    expect(array_column($documents, 'label'))->toBe([
        'Overview',
        'Library',
        'Templates',
        'Generate & Send',
        'Requests',
        'Document Types',
        'Activity',
    ])
        ->and(array_column($documents, 'href'))->not->toContain('/organization/documents/bulk')
        ->and(array_column($documents, 'label'))->not->toContain('Bulk generate');
});

test('templates destination is accessible through any current templates-bridge permission', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.templates'))->toBeFalse();

    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.templates'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'documents.bulk'))->toBeFalse();
});

test('vessels.view cannot unlock the vessel manning destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessels.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessels'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessel-manning'))->toBeFalse();
});
