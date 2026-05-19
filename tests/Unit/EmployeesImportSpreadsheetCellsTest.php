<?php

use App\Imports\EmployeesImport;
use Illuminate\Support\Carbon;

test('shapeRow stringifies integer and whole float employee numbers from spreadsheets', function () {
    $importer = new EmployeesImport(1, 1);
    $mapping = collect(EmployeesImport::fields())
        ->mapWithKeys(fn (string $field) => [$field => $field])
        ->all();

    $rowInt = array_fill_keys(array_keys($mapping), null);
    $rowInt['employee_no'] = 1013;
    $rowInt['name'] = 'Test User';

    $method = new ReflectionMethod(EmployeesImport::class, 'shapeRow');
    $shapedInt = $method->invoke($importer, $rowInt, $mapping);

    expect($shapedInt['employee_no'])->toBe('1013')
        ->and($shapedInt['name'])->toBe('Test User');

    $rowFloat = array_fill_keys(array_keys($mapping), null);
    $rowFloat['employee_no'] = 1013.0;
    $rowFloat['name'] = 'Test User Two';

    $shapedFloat = $method->invoke($importer, $rowFloat, $mapping);

    expect($shapedFloat['employee_no'])->toBe('1013')
        ->and($shapedFloat['name'])->toBe('Test User Two');
});

test('shapeRow converts excel serial date floats into Y-m-d strings', function () {
    $importer = new EmployeesImport(1, 1);
    $mapping = collect(EmployeesImport::fields())
        ->mapWithKeys(fn (string $field) => [$field => $field])
        ->all();

    $serial = 44927.0;
    $expected = Carbon::createFromTimestamp(($serial - 25569) * 86400)->format('Y-m-d');

    $row = array_fill_keys(array_keys($mapping), null);
    $row['employee_no'] = '1';
    $row['name'] = 'Dated';
    $row['date_of_birth'] = $serial;

    $method = new ReflectionMethod(EmployeesImport::class, 'shapeRow');
    $shaped = $method->invoke($importer, $row, $mapping);

    expect($shaped['date_of_birth'])->toBe($expected);
});
