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
