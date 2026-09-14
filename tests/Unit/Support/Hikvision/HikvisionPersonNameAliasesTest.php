<?php

use App\Models\HikvisionPerson;
use App\Support\Hikvision\HikvisionPersonNameAliases;

test('trailing initial aliases include the name without a genuine initial', function (string $fullName) {
    $person = new HikvisionPerson(['full_name' => $fullName]);

    expect(HikvisionPersonNameAliases::forPerson($person))->toContain('Mohammed Rabil');
})->with([
    'Mohammed Rabil T',
    'Mohammed Rabil T.',
]);

test('surnames are not stripped as trailing initials', function (string $fullName) {
    $person = new HikvisionPerson(['full_name' => $fullName]);
    $aliases = HikvisionPersonNameAliases::forPerson($person);
    $firstName = explode(' ', $fullName)[0];

    expect($aliases)->toBe([$fullName])
        ->and($aliases)->not->toContain($firstName);
})->with([
    'Ahmed Ali',
    'John Doe',
    'Sam Lee',
    'Kim Tan',
]);
