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

test('vessels.view cannot unlock the vessel manning destination', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.vessels.view']);

    expect(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessels'))->toBeTrue()
        ->and(NavigationDestinationCatalog::isAccessibleKey($user, 'crew.vessel-manning'))->toBeFalse();
});
