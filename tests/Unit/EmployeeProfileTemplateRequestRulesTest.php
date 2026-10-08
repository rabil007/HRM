<?php

use App\Models\Employee;
use Tests\TestCase;

uses(TestCase::class);
use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Validation\ValidationException;

test('hidden bank field rules are prohibited', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_bank_accounts']['iban']['visible'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $employee = new Employee;
    $employee->setRelation('employeeProfileTemplate', $template);

    $rules = EmployeeProfileTemplateRequestRules::applyToRules($employee, 'employee_bank_accounts', [
        'iban' => ['nullable', 'string', 'max:50'],
        'account_name' => ['nullable', 'string', 'max:200'],
    ]);

    expect($rules['iban'])->toBe(['prohibited'])
        ->and($rules['account_name'])->toContain('nullable');
});

test('template required education certificate uses required rules', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employee_education_qualifications']['university']['visible'] = true;
    $configuration['fields']['employee_education_qualifications']['university']['required'] = true;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $employee = new Employee;
    $employee->setRelation('employeeProfileTemplate', $template);

    $rules = EmployeeProfileTemplateRequestRules::applyToRules($employee, 'employee_education_qualifications', [
        'university' => ['nullable', 'string', 'max:255'],
    ]);

    expect($rules['university'])->toContain('required')
        ->and($rules['university'])->not->toContain('nullable');
});

test('assert tab for table rejects disabled bank tab', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['tabs']['bank']['visible'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $employee = new Employee;
    $employee->setRelation('employeeProfileTemplate', $template);

    EmployeeProfileTemplateRequestRules::assertTabForTable($employee, 'employee_bank_accounts');
})->throws(ValidationException::class);

test('employee number rules stay required when template hides or optionalizes the field', function () {
    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['employee_no']['visible'] = false;
    $configuration['fields']['employees']['employee_no']['required'] = false;

    $template = new EmployeeProfileTemplate([
        'configuration_json' => $configuration,
    ]);

    $employee = new Employee;
    $employee->setRelation('employeeProfileTemplate', $template);

    $rules = EmployeeProfileTemplateRequestRules::applyToRules($employee, 'employees', [
        'employee_no' => ['required', 'string', 'max:50'],
        'phone' => ['nullable', 'string', 'max:30'],
    ]);

    expect($rules['employee_no'])->toContain('required')
        ->and($rules['employee_no'])->not->toContain('nullable')
        ->and($rules['employee_no'])->not->toBe(['prohibited'])
        ->and(EmployeeProfileTemplateRequestRules::isFieldVisible($employee, 'employees', 'employee_no'))->toBeTrue()
        ->and(EmployeeProfileTemplateRequestRules::isFieldRequired($employee, 'employees', 'employee_no'))->toBeTrue();
});
