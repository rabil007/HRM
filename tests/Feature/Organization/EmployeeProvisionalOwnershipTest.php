<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Support\Employees\DraftEmployeeNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, company: Company}
 */
function makeProvisionalOwnershipFixtures(string $slugSuffix = 'own'): array
{
    $user = User::factory()->create();
    $code = strtoupper(substr(md5($slugSuffix), 0, 3));

    $country = Country::query()->create([
        'code' => $code,
        'name' => "Ownership Land {$slugSuffix}",
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $code,
        'name' => "Ownership Currency {$slugSuffix}",
        'symbol' => 'O$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => "Ownership Co {$slugSuffix}",
        'slug' => "ownership-{$slugSuffix}",
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return compact('user', 'company');
}

test('creator can finalize their own provisional employee', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('own-ok');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Owned Draft',
        'idempotency_key' => 'owner-key-aaaaaaaa',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');
    $employee = Employee::query()->findOrFail($employeeId);

    expect($employee->provisional_created_by)->toBe($user->id)
        ->and(DraftEmployeeNumber::isDraft($employee->employee_no))->toBeTrue();

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'OWN-001',
            'name' => 'Owned Draft',
        ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success');

    expect($employee->fresh()->employee_no)->toBe('OWN-001')
        ->and($employee->fresh()->provisional_ensure_key)->toBeNull();
});

test('creator cannot modify another users provisional employee', function () {
    ['user' => $creator, 'company' => $company] = makeProvisionalOwnershipFixtures('own-a');
    $intruder = User::factory()->create();
    $this->actingAs($creator);
    grantCompanyPermissions($creator, $company, ['employees.create']);
    grantCompanyPermissions($intruder, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Creator Draft',
        'idempotency_key' => 'owner-key-bbbbbbbb',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');

    $this->actingAs($intruder)
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'HACK-001',
            'name' => 'Creator Draft',
        ])
        ->assertForbidden();

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('create-only user cannot modify finalized employees', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('final');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'FINAL-1',
        'name' => 'Final Employee',
        'provisional_created_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->put("/organization/employees/{$employee->id}", [
        'employee_no' => 'FINAL-1',
        'name' => 'Changed',
    ])->assertForbidden();

    expect($employee->fresh()->name)->toBe('Final Employee');
});

test('create-only user cannot open another employee via create employee_id query', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-other');
    $other = User::factory()->create();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);
    grantCompanyPermissions($other, $company, ['employees.create']);

    $this->actingAs($other);
    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Other Draft',
        'idempotency_key' => 'owner-key-cccccccc',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->actingAs($user)
        ->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertForbidden();
});

test('create-only user cannot use create url to read a finalized employee', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-final');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'SECRET-1',
        'name' => 'Sensitive Employee',
        'emirates_id' => '784-1234-1234567-1',
        'passport_number' => 'P1234567',
        'provisional_created_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->get("/organization/employees/create?employee_id={$employee->id}")
        ->assertForbidden();
});

test('users cannot access provisional employees belonging to another company', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('xcomp-a');
    ['company' => $otherCompany] = makeProvisionalOwnershipFixtures('xcomp-b');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update']);

    $foreign = Employee::factory()->forCompany($otherCompany)->create([
        'employee_no' => 'DRAFT-FOREIGN1',
        'name' => 'Foreign Draft',
        'provisional_created_by' => $user->id,
    ]);

    $this->put("/organization/employees/{$foreign->id}", [
        'employee_no' => 'XCOMP-1',
        'name' => 'Foreign Draft',
    ])->assertNotFound();

    $this->get("/organization/employees/create?employee_id={$foreign->id}")
        ->assertNotFound();

    expect($foreign->fresh()->employee_no)->toBe('DRAFT-FOREIGN1');
});

test('department-restricted creator can complete their own null-department draft', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-ok');
    $this->actingAs($user);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$department->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Dept Draft',
        'idempotency_key' => 'owner-key-dddddddd',
    ])->assertOk();

    $employeeId = (int) $ensure->json('employee.id');
    expect(Employee::query()->findOrFail($employeeId)->department_id)->toBeNull();

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'DEPT-001',
            'name' => 'Dept Draft',
            'department_id' => $department->id,
        ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toBe('DEPT-001')
        ->and(Employee::query()->findOrFail($employeeId)->department_id)->toBe($department->id);
});

test('department-restricted creator cannot assign unauthorized departments', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-deny');
    $this->actingAs($user);

    $allowed = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine',
        'code' => 'MAR2',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $denied = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF2',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$allowed->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Dept Denied',
        'idempotency_key' => 'owner-key-eeeeeeee',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'DEPT-BAD',
            'name' => 'Dept Denied',
            'department_id' => $denied->id,
        ])
        ->assertSessionHasErrors('department_id');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('employees.update still allows authorized profile edits', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('updater');
    $this->actingAs($user);

    $employee = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'UPD-001',
        'name' => 'Updatable',
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $this->from("/organization/employees/{$employee->id}")
        ->put("/organization/employees/{$employee->id}", [
            'employee_no' => 'UPD-001',
            'name' => 'Updated Name',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($employee->fresh()->name)->toBe('Updated Name');
});

test('create-only completion redirects to create not the view-only profile', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('redir');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Redirect Draft',
        'idempotency_key' => 'owner-key-ffffffff',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'REDIR-1',
        'name' => 'Redirect Draft',
    ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success', 'Employee created successfully.');

    $this->get("/organization/employees/{$employeeId}")->assertForbidden();
});

test('users with view permission still redirect to the employee profile after save', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('view-redir');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'View Redirect',
        'idempotency_key' => 'owner-key-gggggggg',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'VIEW-1',
        'name' => 'View Redirect',
    ])
        ->assertRedirect(route('organization.employees.show', ['employee' => $employeeId]))
        ->assertSessionHas('success');
});

test('unowned legacy drafts cannot be claimed through a guessed employee id', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('legacy');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $legacy = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-LEGACY01',
        'name' => 'Legacy Draft',
        'provisional_created_by' => null,
        'emirates_id' => '784-9999-9999999-9',
    ]);

    $this->get("/organization/employees/create?employee_id={$legacy->id}")
        ->assertForbidden();

    $this->put("/organization/employees/{$legacy->id}", [
        'employee_no' => 'CLAIM-1',
        'name' => 'Legacy Draft',
    ])->assertForbidden();

    expect($legacy->fresh()->employee_no)->toBe('DRAFT-LEGACY01');
});

test('repeated ensure requests with the same idempotency key reuse one provisional record', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('idem');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $first = $this->postJson('/organization/employees/ensure', [
        'name' => 'Idempotent',
        'idempotency_key' => 'owner-key-hhhhhhhh',
    ])->assertOk();

    $second = $this->postJson('/organization/employees/ensure', [
        'name' => 'Idempotent Retry',
        'idempotency_key' => 'owner-key-hhhhhhhh',
    ])->assertOk();

    expect((int) $first->json('employee.id'))->toBe((int) $second->json('employee.id'))
        ->and(Employee::query()->where('company_id', $company->id)->count())->toBe(1);
});

test('idempotency keys cannot reuse another users provisional employee', function () {
    ['user' => $creator, 'company' => $company] = makeProvisionalOwnershipFixtures('idem-iso');
    $other = User::factory()->create();
    grantCompanyPermissions($creator, $company, ['employees.create']);
    grantCompanyPermissions($other, $company, ['employees.create']);

    $this->actingAs($creator)
        ->postJson('/organization/employees/ensure', [
            'name' => 'Creator Idem',
            'idempotency_key' => 'shared-key-iiiiiiii',
        ])
        ->assertOk();

    $this->actingAs($other)
        ->postJson('/organization/employees/ensure', [
            'name' => 'Other Idem',
            'idempotency_key' => 'shared-key-iiiiiiii',
        ])
        ->assertOk();

    expect(Employee::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('refreshing an owned provisional draft via create resume works', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-ok');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Resume Draft',
        'idempotency_key' => 'owner-key-jjjjjjjj',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('mode', 'create')
            ->where('employee.id', $employeeId)
            ->where('employee.name', 'Resume Draft'));
});

test('existing employee relationships survive finalization', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('rels');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.update', 'employees.view']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'With Contract',
        'idempotency_key' => 'owner-key-kkkkkkkk',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $contract = EmployeeContract::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employeeId,
    ]);

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'REL-001',
        'name' => 'With Contract',
    ])->assertRedirect();

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toBe('REL-001')
        ->and(EmployeeContract::query()->whereKey($contract->id)->exists())->toBeTrue()
        ->and((int) EmployeeContract::query()->findOrFail($contract->id)->employee_id)->toBe($employeeId);
});

test('department-restricted creator must select a department before finalizing', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('dept-req');
    $this->actingAs($user);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Req',
        'code' => 'MARR',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$department->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Needs Dept',
        'idempotency_key' => 'owner-key-llllllll',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'NODEPT-1',
        'name' => 'Needs Dept',
    ])->assertSessionHasErrors('department_id');

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('creator can resume owned draft that remains in an authorized department', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-auth-dept');
    $this->actingAs($user);

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Auth',
        'code' => 'MARA',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$department->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'In Scope Draft',
        'idempotency_key' => 'owner-key-mmmmmmmm',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    Employee::query()->whereKey($employeeId)->update(['department_id' => $department->id]);

    $this->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.id', $employeeId)
            ->where('employee.name', 'In Scope Draft'));
});

test('creator cannot resume owned draft after department becomes unauthorized', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('resume-unauth-dept');
    $this->actingAs($user);

    $allowed = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Keep',
        'code' => 'MARK',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $denied = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Deny',
        'code' => 'OFFD',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$allowed->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Moved Draft',
        'idempotency_key' => 'owner-key-nnnnnnnn',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    Employee::query()->whereKey($employeeId)->update(['department_id' => $denied->id]);

    $this->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertForbidden();
});

test('creator cannot modify owned draft after department becomes unauthorized', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('mut-unauth-dept');
    $this->actingAs($user);

    $allowed = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Mut',
        'code' => 'MARM',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $denied = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Mut',
        'code' => 'OFFM',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.create']);
    restrictUserToDepartments($user, $company, [$allowed->id]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Locked Draft',
        'idempotency_key' => 'owner-key-oooooooo',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    Employee::query()->whereKey($employeeId)->update(['department_id' => $denied->id]);

    $this->put("/organization/employees/{$employeeId}", [
        'employee_no' => 'LOCKED-1',
        'name' => 'Locked Draft',
        'department_id' => $allowed->id,
    ])->assertNotFound();

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toStartWith('DRAFT-');
});

test('employees.update cannot modify owned draft outside department scope via ownership', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('upd-bypass');
    $this->actingAs($user);

    $allowed = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Marine Upd',
        'code' => 'MARU',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
    $denied = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office Upd',
        'code' => 'OFFU',
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);

    grantCompanyPermissions($user, $company, ['employees.update', 'employees.create']);
    restrictUserToDepartments($user, $company, [$allowed->id]);

    $draft = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-UPDBYP01',
        'name' => 'Updater Owned',
        'department_id' => $denied->id,
        'provisional_created_by' => $user->id,
    ]);

    $this->put("/organization/employees/{$draft->id}", [
        'employee_no' => 'BYPASS-1',
        'name' => 'Updater Owned',
        'department_id' => $allowed->id,
    ])->assertNotFound();

    expect($draft->fresh()->employee_no)->toBe('DRAFT-UPDBYP01');
});

test('unrestricted updater can modify an in-scope provisional draft', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('upd-ok');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.update', 'employees.view']);

    $draft = Employee::factory()->forCompany($company)->create([
        'employee_no' => 'DRAFT-UPDOK001',
        'name' => 'Updater Draft',
        'provisional_created_by' => null,
    ]);

    $this->put("/organization/employees/{$draft->id}", [
        'employee_no' => 'UPD-OK-1',
        'name' => 'Updater Draft',
    ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($draft->fresh()->employee_no)->toBe('UPD-OK-1');
});

test('successful create-only finalize returns a blank create page without prior employee props', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('fresh-create');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Will Finalize',
        'idempotency_key' => 'owner-key-pppppppp',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');

    $this->followingRedirects()
        ->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'FRESH-1',
            'name' => 'Will Finalize',
        ])
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/employee')
            ->where('mode', 'create')
            ->where('employee.id', null)
            ->where('employee.name', '')
            ->where('employee.employee_no', '')
            ->where('flash.success', 'Employee created successfully.'));

    expect(Employee::query()->findOrFail($employeeId)->employee_no)->toBe('FRESH-1');
});

test('failed provisional validation keeps the same draft and does not finalize', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('fail-keep');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'TAKEN-FRESH',
        'name' => 'Taken',
    ]);

    $ensure = $this->postJson('/organization/employees/ensure', [
        'name' => 'Keep Draft',
        'idempotency_key' => 'owner-key-qqqqqqqq',
    ])->assertOk();
    $employeeId = (int) $ensure->json('employee.id');
    $draftNo = (string) $ensure->json('employee.employee_no');

    $this->from("/organization/employees/create?employee_id={$employeeId}")
        ->put("/organization/employees/{$employeeId}", [
            'employee_no' => 'TAKEN-FRESH',
            'name' => 'Keep Draft Edited',
        ])
        ->assertSessionHasErrors('employee_no');

    $fresh = Employee::query()->findOrFail($employeeId);

    expect($fresh->employee_no)->toBe($draftNo)
        ->and($fresh->name)->toBe('Keep Draft')
        ->and($fresh->provisional_created_by)->toBe($user->id);

    $this->get("/organization/employees/create?employee_id={$employeeId}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('employee.id', $employeeId)
            ->where('employee.employee_no', $draftNo));
});

test('create-only direct store redirects to create not the employee list', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('store-create');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->post('/organization/employees', [
        'employee_no' => 'STORE-CO-1',
        'name' => 'Direct Create Only',
        'start_date' => '2026-02-01',
        'status' => 'active',
    ])
        ->assertRedirect(route('organization.employees.create'))
        ->assertSessionHas('success', 'Employee created successfully.');

    $this->get('/organization/employees')->assertForbidden();

    expect(Employee::query()->where('employee_no', 'STORE-CO-1')->exists())->toBeTrue();
});

test('create plus view direct store still redirects to the employee list', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('store-view');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create', 'employees.view']);

    $this->post('/organization/employees', [
        'employee_no' => 'STORE-VIEW-1',
        'name' => 'Direct Create View',
        'start_date' => '2026-02-01',
        'status' => 'active',
    ])
        ->assertRedirect(route('organization.employees'))
        ->assertSessionHas('success', 'Employee created successfully.');
});

test('unauthorized direct store remains forbidden', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('store-deny');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->post('/organization/employees', [
        'employee_no' => 'STORE-DENY-1',
        'name' => 'Should Fail',
        'start_date' => '2026-02-01',
        'status' => 'active',
    ])->assertForbidden();

    expect(Employee::query()->where('employee_no', 'STORE-DENY-1')->exists())->toBeFalse();
});

test('failed direct store validation does not create an employee', function () {
    ['user' => $user, 'company' => $company] = makeProvisionalOwnershipFixtures('store-fail');
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['employees.create']);

    $this->from('/organization/employees/create')
        ->post('/organization/employees', [
            'employee_no' => '',
            'name' => 'Missing Number',
            'start_date' => '2026-02-01',
            'status' => 'active',
        ])
        ->assertSessionHasErrors('employee_no');

    expect(Employee::query()->where('name', 'Missing Number')->exists())->toBeFalse();
});
