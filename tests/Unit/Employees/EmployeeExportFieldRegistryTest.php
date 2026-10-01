<?php

use App\Support\Employees\EmployeeExportFieldRegistry;

test('export field options expose Position once and do not advertise rank', function () {
    $options = EmployeeExportFieldRegistry::optionsForUser(null);
    $positionOptions = collect($options)->where('label', 'Position')->values();
    $keys = collect($options)->pluck('key');

    expect($positionOptions)->toHaveCount(1)
        ->and($positionOptions->first()['key'])->toBe('position')
        ->and($keys)->not->toContain('rank')
        ->and(EmployeeExportFieldRegistry::definitions())->not->toHaveKey('rank');
});

test('legacy export field rank normalizes to position without duplicates', function () {
    expect(EmployeeExportFieldRegistry::normalizeLegacyFieldKeys(['name', 'rank']))
        ->toBe(['name', 'position'])
        ->and(EmployeeExportFieldRegistry::normalizeLegacyFieldKeys(['position', 'rank']))
        ->toBe(['position'])
        ->and(EmployeeExportFieldRegistry::sanitizeKeys(['name', 'rank', 'position'], null))
        ->toBe(['name', 'position']);
});
