<?php

use Spatie\Permission\Models\Permission;

test('employees identity import permission is not registered', function () {
    expect(
        Permission::query()
            ->where('guard_name', 'web')
            ->where('name', 'employees.identity.import')
            ->exists(),
    )->toBeFalse();
});
