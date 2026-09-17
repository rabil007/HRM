<?php

use App\Support\Authorization\Presenters\PermissionOptionPresenter;

test('fromArray safely handles missing optional label key', function () {
    $presented = PermissionOptionPresenter::fromArray([
        'id' => 42,
        'name' => 'employees.view',
        'description' => 'Allows the user to view employees.',
    ]);

    expect($presented)->toMatchArray([
        'id' => 42,
        'name' => 'employees.view',
        'label' => 'View Employees',
        'description' => 'Allows the user to view employees.',
        'group' => 'Employees',
    ]);
});
