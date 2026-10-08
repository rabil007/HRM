<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\Employees\Actions\CreateEmployeeFromName;
use App\Support\Employees\DraftEmployeeNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function makeEmployeeNumberFixtures(string $slugSuffix = 'eno'): array
{
    $user = User::factory()->create();
    $code = strtoupper(substr(md5($slugSuffix), 0, 3));

    $country = Country::query()->create([
        'code' => $code,
        'name' => "Employee Number Land {$slugSuffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "Employee Number Currency {$slugSuffix}",
        'symbol' => 'E$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => "Employee Number Co {$slugSuffix}",
        'slug' => "employee-number-{$slugSuffix}",
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

test('draft employee number helper detects provisional identifiers', function () {
    expect(DraftEmployeeNumber::isDraft('DRAFT-FEIXOIU9'))->toBeTrue()
        ->and(DraftEmployeeNumber::isDraft('draft-abcdefgh'))->toBeTrue()
        ->and(DraftEmployeeNumber::isDraft('EMP-1001'))->toBeFalse()
        ->and(DraftEmployeeNumber::isDraft(''))->toBeFalse()
        ->and(DraftEmployeeNumber::isDraft(null))->toBeFalse();
});

test('employee update accepts a valid official employee number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('valid');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-ABCD1234',
        'name' => 'Provisional Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'EMP-1001',
            'name' => 'Provisional Employee',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->employee_no)->toBe('EMP-1001');
});

test('employee update rejects blank employee numbers with validation error not 500', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('blank');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-ABCD1234',
        'name' => 'Blank Number Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => '',
            'name' => 'Blank Number Employee',
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('DRAFT-ABCD1234');
});

test('employee update rejects whitespace-only employee numbers', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('ws');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'KEEP-001',
        'name' => 'Whitespace Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => '   ',
            'name' => 'Whitespace Employee',
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('KEEP-001');
});

test('employee update rejects explicit null employee_no without database exception', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('null');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'KEEP-002',
        'name' => 'Null Number Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->putJson("/organization/employees/{$employee->id}", [
            'employee_no' => null,
            'name' => 'Null Number Employee',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('KEEP-002');
});

test('employee update preserves existing employee number when field is omitted', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('omit');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'KEEP-003',
        'name' => 'Partial Update Employee',
        'phone' => '0500000000',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'name' => 'Partial Update Employee',
            'phone' => '0501111111',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->employee_no)->toBe('KEEP-003')
        ->and($employee->fresh()->phone)->toBe('0501111111');
});

test('employee update requires official employee number even when template marked it optional or hidden', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('tpl');
    $this->actingAs($user);

    $configuration = EmployeeProfileTemplateFieldRegistry::defaultConfiguration();
    $configuration['fields']['employees']['employee_no']['visible'] = false;
    $configuration['fields']['employees']['employee_no']['required'] = false;

    $template = createEmployeeProfileTemplate($company, 'Optional Number Template', $configuration);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'DRAFT-TPL00001',
        'name' => 'Template Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->get("/organization/employees/{$employee->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('resolved_template.fields.employees.employee_no.visible', true)
            ->where('resolved_template.fields.employees.employee_no.required', true));

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => null,
            'name' => 'Template Employee',
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('DRAFT-TPL00001');
});

test('employee update rejects duplicate employee numbers within the same company', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('dup');
    $this->actingAs($user);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DUP-001',
        'name' => 'Existing',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DUP-002',
        'name' => 'Other',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'DUP-001',
            'name' => 'Other',
        ])
        ->assertSessionHasErrors([
            'employee_no' => 'This employee number is already used in your company. Choose a different number.',
        ]);
});

test('employee update rejects numbers belonging to soft-deleted employees', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('soft');
    $this->actingAs($user);

    $deleted = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SOFT-001',
        'name' => 'Deleted',
    ]);
    $deleted->delete();

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SOFT-002',
        'name' => 'Live',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'SOFT-001',
            'name' => 'Live',
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('SOFT-002');
});

test('employee update is tenant isolated for employee number uniqueness', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('tenant-a');
    ['company' => $otherCompany] = makeEmployeeNumberFixtures('tenant-b');
    $this->actingAs($user);

    Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'SHARED-1',
        'name' => 'Other Company Employee',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'LOCAL-1',
        'name' => 'Local Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'SHARED-1',
            'name' => 'Local Employee',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->employee_no)->toBe('SHARED-1');
});

test('employee update rejects cross-company employee access', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('xcomp-a');
    ['company' => $otherCompany] = makeEmployeeNumberFixtures('xcomp-b');
    $this->actingAs($user);

    $foreign = Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'FOREIGN-1',
        'name' => 'Foreign Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->put("/organization/employees/{$foreign->id}", [
        'employee_no' => 'HACKED-1',
        'name' => 'Foreign Employee',
    ])->assertNotFound();

    expect($foreign->fresh()->employee_no)->toBe('FOREIGN-1');
});

test('ensure creates provisional draft number and profile save converts to official number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('ensure');
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Captain Ahmed',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');
    $draftNo = (string) $ensure->json('employee.employee_no');

    expect($draftNo)->toStartWith('DRAFT-')
        ->and(DraftEmployeeNumber::isDraft($draftNo))->toBeTrue();

    $this->from("/organization/employees/{$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => $draftNo,
            'name' => 'Captain Ahmed',
        ])
        ->assertSessionHasErrors('employee_no');

    expect(Employee::query()->find($employeeId)?->employee_no)->toBe($draftNo);

    $this->from("/organization/employees/{$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'OFFICIAL-77',
            'name' => 'Captain Ahmed',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(Employee::query()->find($employeeId)?->employee_no)->toBe('OFFICIAL-77');
});

test('create employee from name keeps unique draft numbers and does not overwrite later official numbers', function () {
    ['company' => $company] = makeEmployeeNumberFixtures('action');

    $first = app(CreateEmployeeFromName::class)->handle('First Draft', $company->id);
    $second = app(CreateEmployeeFromName::class)->handle('Second Draft', $company->id);

    expect($first->employee_no)->toStartWith('DRAFT-')
        ->and($second->employee_no)->toStartWith('DRAFT-')
        ->and($first->employee_no)->not->toBe($second->employee_no);

    $first->update(['employee_no' => 'OFFICIAL-1']);

    expect($first->fresh()->employee_no)->toBe('OFFICIAL-1')
        ->and($second->fresh()->employee_no)->toStartWith('DRAFT-');
});

test('create-only user can update provisional draft employee to official number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('create-only');
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Create Only Employee',
        'idempotency_key' => 'create-only-key-001',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');

    expect(Employee::query()->find($employeeId)?->provisional_created_by)->toBe($user->id);

    $this->from("/organization/employees/{$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'CREATE-ONLY-1',
            'name' => 'Create Only Employee',
        ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success');

    expect(Employee::query()->find($employeeId)?->employee_no)->toBe('CREATE-ONLY-1');
});

test('create-only user cannot update official employee profiles', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('create-block');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'OFFICIAL-1',
        'name' => 'Official Employee',
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'OFFICIAL-1',
            'name' => 'Attempted Edit',
        ])
        ->assertForbidden();

    expect($employee->fresh()->name)->toBe('Official Employee');
});

test('failed validation does not update persisted employee number', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('fail-persist');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-FAIL001',
        'name' => 'Draft Employee',
    ]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TAKEN-1',
        'name' => 'Existing',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'TAKEN-1',
            'name' => 'Draft Employee',
        ])
        ->assertSessionHasErrors('employee_no');

    expect($employee->fresh()->employee_no)->toBe('DRAFT-FAIL001');
});

test('storing a new employee rejects draft employee numbers', function () {
    ['user' => $user, 'company' => $company] = makeEmployeeNumberFixtures('store-draft');
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $this->from('/organization/employees/create')
        ->post('/organization/employees', [
            'employee_no' => 'DRAFT-ABCDEFGH',
            'name' => 'Should Fail',
            'start_date' => '2026-02-01',
        ])
        ->assertSessionHasErrors('employee_no');

    expect(Employee::query()->where('name', 'Should Fail')->exists())->toBeFalse();
});
