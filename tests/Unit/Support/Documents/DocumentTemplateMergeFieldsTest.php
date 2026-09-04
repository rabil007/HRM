<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Rank;
use App\Support\Documents\DocumentTemplateMergeFields;

function createMergeFieldsTestCompany(string $name = 'Test Co'): Company
{
    $code = strtoupper((string) fake()->unique()->lexify('??'));
    $country = Country::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'dial_code' => '+999', 'is_active' => true],
    );
    $currency = Currency::query()->firstOrCreate(
        ['code' => $code],
        ['name' => "Test {$code}", 'symbol' => '$', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => strtolower($code).'-'.fake()->unique()->numberBetween(1000, 9999),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('labelFor maps merge keys to designer labels', function () {
    expect(DocumentTemplateMergeFields::labelFor('{{employee_name}}'))->toBe('Employee Full Name')
        ->and(DocumentTemplateMergeFields::labelFor('employee_name'))->toBe('Employee Full Name')
        ->and(DocumentTemplateMergeFields::labelFor('{{today}}'))->toBe('Today\'s Date')
        ->and(DocumentTemplateMergeFields::labelFor(''))->toBeNull()
        ->and(DocumentTemplateMergeFields::labelFor('placement-001'))->toBeNull();
});

test('definitions returns allowed merge fields with required keys', function () {
    $defs = DocumentTemplateMergeFields::definitions();

    expect($defs)->not->toBeEmpty();
    foreach ($defs as $def) {
        expect($def)->toHaveKeys(['key', 'label', 'category', 'sample']);
        expect($def['key'])->toStartWith('{{')->toEndWith('}}');
    }
});

test('allowed keys contain expected core fields', function () {
    $keys = DocumentTemplateMergeFields::allowedKeys();

    expect($keys)->toContain(
        '{{employee_name}}',
        '{{employee_no}}',
        '{{first_name}}',
        '{{last_name}}',
        '{{email}}',
        '{{phone}}',
        '{{gender}}',
        '{{joining_date}}',
        '{{nationality}}',
        '{{emirates_id}}',
        '{{position_name}}',
        '{{rank_name}}',
        '{{manager_name}}',
        '{{company_name}}',
        '{{department_name}}',
        '{{branch_name}}',
        '{{today}}',
        '{{current_year}}',
    );
    expect($keys)->not->toContain('{{passport_number}}');
});

test('find unsupported detects invalid or forbidden placeholders', function () {
    $content = 'Hello {{employee_name}}, your bank account is {{bank_account}} and salary is {{salary}}. Passport {{passport_number}}.';
    $unsupported = DocumentTemplateMergeFields::findUnsupported($content);

    expect($unsupported)->toEqualCanonicalizing(['{{bank_account}}', '{{salary}}', '{{passport_number}}']);
});

test('find unsupported returns empty array when all placeholders are valid', function () {
    $content = 'To {{employee_name}} ({{employee_no}}) Emirates ID {{emirates_id}} at {{company_name}} on {{today}}.';
    $unsupported = DocumentTemplateMergeFields::findUnsupported($content);

    expect($unsupported)->toBeEmpty();
});

test('sample values maps valid keys to sample strings', function () {
    $samples = DocumentTemplateMergeFields::sampleValues('Acme Marine');

    expect($samples['{{company_name}}'])->toBe('Acme Marine');
    expect($samples['{{employee_name}}'])->toBe('Jane Smith');
    expect($samples['{{today}}'])->toBe(now()->format('d M Y'));
});

test('values for employee maps employee attributes to placeholders', function () {
    $company = createMergeFieldsTestCompany('Atlantic Shipping');
    $department = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck']);
    $position = Position::query()->create(['company_id' => $company->id, 'title' => 'First Officer']);
    $rank = Rank::query()->create(['name' => 'Captain', 'is_active' => true]);
    $nationality = Country::query()->firstOrCreate(
        ['code' => 'PH'],
        ['name' => 'Philippines', 'dial_code' => '+63', 'is_active' => true],
    );

    $employee = Employee::factory()->create([
        'company_id' => $company->id,
        'department_id' => $department->id,
        'position_id' => $position->id,
        'rank_id' => $rank->id,
        'nationality_id' => $nationality->id,
        'passport_number' => 'P99887766',
        'emirates_id' => '784-2000-1234567-1',
        'name' => 'Johnathan Doe',
        'employee_no' => 'EMP-999',
        'work_email' => 'john.doe@atlantic.com',
        'hire_date' => '2023-05-10',
    ]);

    $values = DocumentTemplateMergeFields::valuesForEmployee($employee);

    expect($values['{{employee_name}}'])->toBe('Johnathan Doe');
    expect($values['{{employee_no}}'])->toBe('EMP-999');
    expect($values['{{email}}'])->toBe('john.doe@atlantic.com');
    expect($values['{{company_name}}'])->toBe('Atlantic Shipping');
    expect($values['{{department_name}}'])->toBe('Deck');
    expect($values['{{position_name}}'])->toBe('First Officer');
    expect($values['{{rank_name}}'])->toBe('Captain');
    expect($values['{{nationality}}'])->toBe('Philippines');
    expect($values['{{emirates_id}}'])->toBe('784-2000-1234567-1');
    expect($values)->not->toHaveKey('{{passport_number}}');
    expect($values['{{joining_date}}'])->toBe('10 May 2023');
    expect($values['{{manager_name}}'])->toBe('');
});

test('values for employee maps department effective manager name', function () {
    $company = createMergeFieldsTestCompany('Pacific Crew');
    $manager = Employee::factory()->forCompany($company)->create([
        'name' => 'Sara Manager',
        'employee_no' => 'MGR-001',
    ]);
    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Deck',
        'manager_id' => $manager->id,
    ]);
    $employee = Employee::factory()->forCompany($company)->inDepartment($department)->create([
        'name' => 'Alex Seafarer',
        'employee_no' => 'EMP-100',
    ]);

    $values = DocumentTemplateMergeFields::valuesForEmployee($employee);

    expect($values['{{manager_name}}'])->toBe('Sara Manager');
});

test('apply replaces known placeholders and preserves unknown ones', function () {
    $content = 'Employee {{employee_name}} at {{company_name}} has badge {{badge_id}}.';
    $values = [
        '{{employee_name}}' => 'Alice',
        '{{company_name}}' => 'Acme Corp',
    ];

    $result = DocumentTemplateMergeFields::apply($content, $values);

    expect($result)->toBe('Employee Alice at Acme Corp has badge {{badge_id}}.');
});
