<?php

use App\Support\Employees\EmployeeSmartSearchConceptRegistry;

test('it exposes only approved concepts and operators', function () {
    expect(EmployeeSmartSearchConceptRegistry::has('email'))->toBeTrue()
        ->and(EmployeeSmartSearchConceptRegistry::allows('email', 'missing'))->toBeTrue()
        ->and(EmployeeSmartSearchConceptRegistry::allows('status', 'equals'))->toBeTrue()
        ->and(EmployeeSmartSearchConceptRegistry::allows('status', 'missing'))->toBeFalse()
        ->and(EmployeeSmartSearchConceptRegistry::isComposite('email'))->toBeTrue()
        ->and(EmployeeSmartSearchConceptRegistry::isComposite('work_email'))->toBeFalse();
});

test('it rejects arbitrary, payroll, and identity concepts', function (string $concept) {
    expect(EmployeeSmartSearchConceptRegistry::has($concept))->toBeFalse()
        ->and(EmployeeSmartSearchConceptRegistry::excludedConcepts())->toContain($concept);
})->with([
    'salary',
    'iban',
    'company_id',
    'manager',
    'bank_account',
]);

test('vehicle concepts are not in the registry', function () {
    expect(EmployeeSmartSearchConceptRegistry::has('car'))->toBeFalse()
        ->and(EmployeeSmartSearchConceptRegistry::has('vehicle'))->toBeFalse();
});
